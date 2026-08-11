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
    respostas_pre_definidas INT NOT NULL DEFAULT 0,
    INDEX idx_indicadores_nome (nome)
);

CREATE TABLE IF NOT EXISTS resultados (
    id_empresa INT NOT NULL,
    id_indicador INT NOT NULL,
    referencia VARCHAR(4) NOT NULL,
    `data` DATE NULL DEFAULT NULL,
    `decimal` DECIMAL(20,4) NULL DEFAULT NULL,
    texto TEXT NULL DEFAULT NULL,
    id_resposta_definida INT NULL DEFAULT NULL,
    UNIQUE KEY uniq_resultados_empresa_indicador_referencia (id_empresa, id_indicador, referencia),
    INDEX idx_resultados_id_indicador (id_indicador),
    INDEX idx_resultados_id_resposta_definida (id_resposta_definida),
    CONSTRAINT fk_resultados_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE,
    CONSTRAINT fk_resultados_indicador FOREIGN KEY (id_indicador) REFERENCES indicadores (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS respostas_pre_definidas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_indicador INT NOT NULL,
    texto VARCHAR(255) NOT NULL,
    ponto INT NOT NULL DEFAULT 0,
    INDEX idx_respostas_pre_definidas_id_indicador (id_indicador),
    CONSTRAINT fk_respostas_pre_definidas_indicador FOREIGN KEY (id_indicador) REFERENCES indicadores (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS comentarios_indicadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    comentario TEXT NULL DEFAULT NULL,
    id_indicador INT NOT NULL,
    id_empresa INT NOT NULL,
    UNIQUE KEY uniq_comentarios_indicadores_empresa_indicador (id_empresa, id_indicador),
    INDEX idx_comentarios_indicadores_id_indicador (id_indicador),
    CONSTRAINT fk_comentarios_indicadores_indicador FOREIGN KEY (id_indicador) REFERENCES indicadores (id) ON DELETE CASCADE,
    CONSTRAINT fk_comentarios_indicadores_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS resultados_ocultos (
    id_empresa INT NOT NULL,
    tipo ENUM('linha', 'coluna') NOT NULL,
    chave VARCHAR(32) NOT NULL,
    PRIMARY KEY (id_empresa, tipo, chave),
    CONSTRAINT fk_resultados_ocultos_empresa FOREIGN KEY (id_empresa) REFERENCES empresas (id) ON DELETE CASCADE
);
