CREATE TABLE IF NOT EXISTS empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_da_empresa VARCHAR(255) NOT NULL,
    cod_cvm VARCHAR(20) NULL DEFAULT NULL,
    INDEX idx_empresas_nome_da_empresa (nome_da_empresa),
    INDEX idx_empresas_cod_cvm (cod_cvm)
);


CREATE TABLE IF NOT EXISTS indicadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT NULL DEFAULT NULL,
    formato ENUM('Moeda', 'Porcentagem', 'Número Inteiro', 'Data', 'Texto') NOT NULL,
    INDEX idx_indicadores_nome (nome)
);

CREATE TABLE IF NOT EXISTS referencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    periodo_base ENUM('trimestre', 'semestre', 'anual') NOT NULL,
    periodo_referencia INT NULL DEFAULT NULL,
    ano INT NOT NULL,
    id_empresa INT NOT NULL,
    INDEX idx_referencias_id_empresa (id_empresa),
    UNIQUE KEY uniq_referencias_empresa_periodo (id_empresa, periodo_base, periodo_referencia, ano),
    CONSTRAINT fk_referencias_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS resultados (
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
);
