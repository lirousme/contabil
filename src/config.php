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


function ensureResultadosTables(PDO $pdo): void
{
    ensureEmpresasTable($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS indicadores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(255) NOT NULL,
            descricao TEXT NULL DEFAULT NULL,
            formato ENUM('Moeda', 'Porcentagem', 'Data', 'Texto') NOT NULL,
            INDEX idx_indicadores_nome (nome)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS referencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            periodo_base ENUM('trimestre', 'semestre', 'anual') NOT NULL,
            periodo_referencia INT NULL DEFAULT NULL,
            ano INT NOT NULL,
            id_empresa INT NOT NULL,
            INDEX idx_referencias_id_empresa (id_empresa),
            UNIQUE KEY uniq_referencias_empresa_periodo (id_empresa, periodo_base, periodo_referencia, ano),
            CONSTRAINT fk_referencias_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS resultados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_referencia INT NOT NULL,
            id_indicador INT NOT NULL,
            `data` DATE NULL DEFAULT NULL,
            `decimal` DECIMAL(20,4) NULL DEFAULT NULL,
            texto TEXT NULL DEFAULT NULL,
            UNIQUE KEY uniq_resultados_referencia_indicador (id_referencia, id_indicador),
            INDEX idx_resultados_id_indicador (id_indicador),
            CONSTRAINT fk_resultados_referencia FOREIGN KEY (id_referencia) REFERENCES referencias (id) ON DELETE CASCADE,
            CONSTRAINT fk_resultados_indicador FOREIGN KEY (id_indicador) REFERENCES indicadores (id) ON DELETE CASCADE
        )"
    );

    ensureIndex($pdo, 'indicadores', 'idx_indicadores_nome', 'CREATE INDEX idx_indicadores_nome ON indicadores (nome)');
    ensureIndex($pdo, 'referencias', 'idx_referencias_id_empresa', 'CREATE INDEX idx_referencias_id_empresa ON referencias (id_empresa)');
    ensureIndex($pdo, 'resultados', 'idx_resultados_id_indicador', 'CREATE INDEX idx_resultados_id_indicador ON resultados (id_indicador)');
}
