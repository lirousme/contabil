<?php

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    loadEnv(__DIR__ . '/../.env');

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $name = $_ENV['DB_NAME'] ?? '';
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';

    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function ensureEmpresasTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS empresas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome_da_empresa VARCHAR(255) NOT NULL,
            cod_cvm VARCHAR(20) NULL DEFAULT NULL,
            INDEX idx_empresas_nome_da_empresa (nome_da_empresa),
            INDEX idx_empresas_cod_cvm (cod_cvm)
        )'
    );

    ensureCodCvmColumn($pdo);
    ensureIndex($pdo, 'empresas', 'idx_empresas_nome_da_empresa', 'CREATE INDEX idx_empresas_nome_da_empresa ON empresas (nome_da_empresa)');
    ensureIndex($pdo, 'empresas', 'idx_empresas_cod_cvm', 'CREATE INDEX idx_empresas_cod_cvm ON empresas (cod_cvm)');
    ensureTickersTable($pdo);
}

function ensureTickersTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tickers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_empresa INT NOT NULL,
            bolsa VARCHAR(20) NOT NULL,
            ticker VARCHAR(30) NOT NULL,
            INDEX idx_tickers_id_empresa (id_empresa),
            INDEX idx_tickers_bolsa (bolsa),
            CONSTRAINT fk_tickers_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE
        )'
    );

    ensureIndex($pdo, 'tickers', 'idx_tickers_id_empresa', 'CREATE INDEX idx_tickers_id_empresa ON tickers (id_empresa)');
    ensureIndex($pdo, 'tickers', 'idx_tickers_bolsa', 'CREATE INDEX idx_tickers_bolsa ON tickers (bolsa)');
}

function ensureCodCvmColumn(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'cod_cvm'"
    );

    if ($stmt->fetchColumn() !== 'varchar') {
        $pdo->exec('ALTER TABLE empresas MODIFY cod_cvm VARCHAR(20) NULL DEFAULT NULL');
    }
}

function ensureIndex(PDO $pdo, string $table, string $index, string $sql): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
    );
    $stmt->execute(['table' => $table, 'index' => $index]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}


function ensureResultadosTables(PDO $pdo): void
{
    ensureEmpresasTable($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS indicadores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(255) NOT NULL,
            descricao TEXT NULL DEFAULT NULL,
            formato ENUM('Moeda', 'Porcentagem', 'Número Inteiro', 'Data', 'Texto') NOT NULL,
            INDEX idx_indicadores_nome (nome)
        )"
    );

    $pdo->exec("ALTER TABLE indicadores MODIFY formato ENUM('Moeda', 'Porcentagem', 'Número Inteiro', 'Data', 'Texto') NOT NULL");

    if (tableHasColumn($pdo, 'resultados', 'id_referencia')) {
        $pdo->exec('DROP TABLE IF EXISTS resultados_nova');
        $pdo->exec(
            "CREATE TABLE resultados_nova (
                id_empresa INT NOT NULL,
                id_indicador INT NOT NULL,
                referencia VARCHAR(4) NOT NULL,
                `data` DATE NULL DEFAULT NULL,
                `decimal` DECIMAL(20,4) NULL DEFAULT NULL,
                texto TEXT NULL DEFAULT NULL,
                UNIQUE KEY uniq_resultados_empresa_indicador_referencia (id_empresa, id_indicador, referencia),
                INDEX idx_resultados_id_indicador (id_indicador),
                CONSTRAINT fk_resultados_nova_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE,
                CONSTRAINT fk_resultados_nova_indicador FOREIGN KEY (id_indicador) REFERENCES indicadores (id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            "INSERT INTO resultados_nova (id_empresa, id_indicador, referencia, `data`, `decimal`, texto)
            SELECT referencias.id_empresa, resultados.id_indicador,
                CASE referencias.periodo_base
                    WHEN 'trimestre' THEN CONCAT(referencias.periodo_referencia, 'T', RIGHT(referencias.ano, 2))
                    WHEN 'semestre' THEN CONCAT(referencias.periodo_referencia, 'S', RIGHT(referencias.ano, 2))
                    ELSE CONCAT('A', RIGHT(referencias.ano, 2))
                END,
                resultados.`data`, resultados.`decimal`, resultados.texto
            FROM resultados
            INNER JOIN referencias ON referencias.id = resultados.id_referencia
            ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `decimal` = VALUES(`decimal`), texto = VALUES(texto)"
        );
        $pdo->exec('DROP TABLE resultados');
        $pdo->exec('RENAME TABLE resultados_nova TO resultados');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS resultados (
            id_empresa INT NOT NULL,
            id_indicador INT NOT NULL,
            referencia VARCHAR(4) NOT NULL,
            `data` DATE NULL DEFAULT NULL,
            `decimal` DECIMAL(20,4) NULL DEFAULT NULL,
            texto TEXT NULL DEFAULT NULL,
            UNIQUE KEY uniq_resultados_empresa_indicador_referencia (id_empresa, id_indicador, referencia),
            INDEX idx_resultados_id_indicador (id_indicador),
            CONSTRAINT fk_resultados_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE,
            CONSTRAINT fk_resultados_indicador FOREIGN KEY (id_indicador) REFERENCES indicadores (id) ON DELETE CASCADE
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comentarios_indicadores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comentario TEXT NULL DEFAULT NULL,
            id_indicador INT NOT NULL,
            id_empresa INT NOT NULL,
            UNIQUE KEY uniq_comentarios_indicadores_empresa_indicador (id_empresa, id_indicador),
            INDEX idx_comentarios_indicadores_id_indicador (id_indicador),
            CONSTRAINT fk_comentarios_indicadores_indicador FOREIGN KEY (id_indicador) REFERENCES indicadores (id) ON DELETE CASCADE,
            CONSTRAINT fk_comentarios_indicadores_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE
        )"
    );

    $pdo->exec('DROP TABLE IF EXISTS referencias');

    ensureIndex($pdo, 'indicadores', 'idx_indicadores_nome', 'CREATE INDEX idx_indicadores_nome ON indicadores (nome)');
    ensureIndex($pdo, 'resultados', 'idx_resultados_id_indicador', 'CREATE INDEX idx_resultados_id_indicador ON resultados (id_indicador)');
    ensureIndex($pdo, 'comentarios_indicadores', 'idx_comentarios_indicadores_id_indicador', 'CREATE INDEX idx_comentarios_indicadores_id_indicador ON comentarios_indicadores (id_indicador)');
}
