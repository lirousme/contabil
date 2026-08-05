CREATE TABLE IF NOT EXISTS empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_da_empresa VARCHAR(255) NOT NULL,
    cod_cvm INT NULL DEFAULT NULL,
    INDEX idx_empresas_nome_da_empresa (nome_da_empresa),
    INDEX idx_empresas_cod_cvm (cod_cvm)
);
