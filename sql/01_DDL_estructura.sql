
3-- ============================================================
-- ARCHIVO: 01_DDL_estructura.sql
-- DESCRIPCIÓN: Data Definition Language — Creación del esquema
-- PROYECTO: Sistema de Gestión de Encuestas (Cali)
-- ============================================================

CREATE DATABASE IF NOT EXISTS encuestas_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE encuestas_db;

-- 1. Tabla de Perfiles
CREATE TABLE IF NOT EXISTS perfiles (
    id_perfil     INT          NOT NULL AUTO_INCREMENT,
    nombre_perfil VARCHAR(50)  NOT NULL UNIQUE,
    descripcion   TEXT,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_perfiles PRIMARY KEY (id_perfil)
) ENGINE=InnoDB COMMENT='Perfiles de acceso al sistema';

-- 2. Puntos de Atención (8 estáticos de Cali)
CREATE TABLE IF NOT EXISTS puntos_atencion (
    id_punto             INT          NOT NULL AUTO_INCREMENT,
    nombre_punto         VARCHAR(100) NOT NULL,
    numero_comuna        INT          NOT NULL,
    nombre_comuna        VARCHAR(100) NOT NULL,
    direccion            VARCHAR(200),
    es_oficina_principal TINYINT(1)   NOT NULL DEFAULT 0,
    activo               TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_puntos PRIMARY KEY (id_punto)
) ENGINE=InnoDB COMMENT='Puntos de atención estáticos en Cali';

-- 3. Usuarios del Sistema
CREATE TABLE IF NOT EXISTS usuarios_sistema (
    id_usuario          INT          NOT NULL AUTO_INCREMENT,
    nombre              VARCHAR(100) NOT NULL,
    apellido            VARCHAR(100) NOT NULL,
    email               VARCHAR(100) NOT NULL UNIQUE,
    username            VARCHAR(50)  NOT NULL UNIQUE,
    password_hash       VARCHAR(255) NOT NULL,
    id_perfil           INT          NOT NULL,
    id_punto_atencion   INT,
    telefono            VARCHAR(15),
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    ultimo_login        DATETIME,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT pk_usuarios    PRIMARY KEY (id_usuario),
    CONSTRAINT fk_usr_perfil  FOREIGN KEY (id_perfil)         REFERENCES perfiles(id_perfil),
    CONSTRAINT fk_usr_punto   FOREIGN KEY (id_punto_atencion) REFERENCES puntos_atencion(id_punto)
) ENGINE=InnoDB COMMENT='Usuarios de la aplicación';

-- 4. Comunas de Cali (Urbanas y Rurales)
CREATE TABLE IF NOT EXISTS comunas (
    id_comuna      INT          NOT NULL AUTO_INCREMENT,
    numero_comuna  INT          NOT NULL UNIQUE,
    nombre_comuna  VARCHAR(100) NOT NULL,
    tipo           ENUM('urbana','rural') NOT NULL DEFAULT 'urbana',
    CONSTRAINT pk_comunas PRIMARY KEY (id_comuna)
) ENGINE=InnoDB COMMENT='División político-administrativa de comunas y corregimientos';

-- 5. Barrios de Cali
CREATE TABLE IF NOT EXISTS barrios (
    id_barrio     INT          NOT NULL AUTO_INCREMENT,
    nombre_barrio VARCHAR(100) NOT NULL,
    id_comuna     INT          NOT NULL,
    CONSTRAINT pk_barrios    PRIMARY KEY (id_barrio),
    CONSTRAINT fk_bar_comuna FOREIGN KEY (id_comuna) REFERENCES comunas(id_comuna)
) ENGINE=InnoDB COMMENT='Barrios de Cali asociados a comunas';

-- 6. Ciudadanos Solicitantes
CREATE TABLE IF NOT EXISTS ciudadanos (
    id_ciudadano       INT          NOT NULL AUTO_INCREMENT,
    tipo_documento     ENUM('CC','CE','TI','PP','NIT') NOT NULL,
    numero_documento   VARCHAR(20)  NOT NULL,
    nombres            VARCHAR(100) NOT NULL,
    apellidos          VARCHAR(100) NOT NULL,
    celular            VARCHAR(15)  NOT NULL,
    email              VARCHAR(100),
    fecha_nacimiento   DATE,
    direccion          VARCHAR(200) NOT NULL,
    id_barrio          INT          NOT NULL,
    id_comuna          INT          NOT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_ciudadanos   PRIMARY KEY (id_ciudadano),
    CONSTRAINT uq_documento    UNIQUE (tipo_documento, numero_documento),
    CONSTRAINT fk_ciu_barrio   FOREIGN KEY (id_barrio) REFERENCES barrios(id_barrio),
    CONSTRAINT fk_ciu_comuna   FOREIGN KEY (id_comuna) REFERENCES comunas(id_comuna)
) ENGINE=InnoDB COMMENT='Ciudadanos que solicitan encuestas domiciliarias';

-- 7. Solicitudes de Encuesta Radicadas
CREATE TABLE IF NOT EXISTS solicitudes_encuesta (
    id_solicitud           INT          NOT NULL AUTO_INCREMENT,
    numero_radicado        VARCHAR(20)  NOT NULL UNIQUE,
    id_ciudadano           INT          NOT NULL,
    id_punto_atencion      INT          NOT NULL,
    id_usuario_ventanilla  INT          NOT NULL,
    fecha_radicacion       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_limite           DATE         NOT NULL,
    latitud                DECIMAL(10,8),
    longitud               DECIMAL(11,8),
    estado                 ENUM('pendiente','asignada','en_proceso','realizada','revisada','apta','no_apta')
                           NOT NULL DEFAULT 'pendiente',
    observaciones          TEXT,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT pk_solicitudes      PRIMARY KEY (id_solicitud),
    CONSTRAINT fk_sol_ciudadano    FOREIGN KEY (id_ciudadano)          REFERENCES ciudadanos(id_ciudadano),
    CONSTRAINT fk_sol_punto        FOREIGN KEY (id_punto_atencion)     REFERENCES puntos_atencion(id_punto),
    CONSTRAINT fk_sol_ventanilla   FOREIGN KEY (id_usuario_ventanilla) REFERENCES usuarios_sistema(id_usuario)
) ENGINE=InnoDB COMMENT='Solicitudes de encuesta con coordenadas y SLA legal';

-- Índices de Rendimiento
CREATE INDEX idx_solicitud_estado       ON solicitudes_encuesta(estado);
CREATE INDEX idx_solicitud_fecha_rad    ON solicitudes_encuesta(fecha_radicacion);
CREATE INDEX idx_solicitud_fecha_limite ON solicitudes_encuesta(fecha_limite);

-- 8. Hojas de Ruta
CREATE TABLE IF NOT EXISTS hojas_ruta (
    id_hoja_ruta      INT          NOT NULL AUTO_INCREMENT,
    id_encuestador    INT          NOT NULL,
    id_enrutador      INT          NOT NULL,
    id_punto_entrega  INT          NOT NULL,
    fecha_asignacion  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_trabajo     DATE         NOT NULL,
    zona_tipo         ENUM('urbana','rural') NOT NULL DEFAULT 'urbana',
    total_encuestas   INT          NOT NULL DEFAULT 0,
    estado            ENUM('generada','entregada','en_proceso','completada')
                      NOT NULL DEFAULT 'generada',
    CONSTRAINT pk_hojas_ruta      PRIMARY KEY (id_hoja_ruta),
    CONSTRAINT fk_hr_encuestador  FOREIGN KEY (id_encuestador)   REFERENCES usuarios_sistema(id_usuario),
    CONSTRAINT fk_hr_enrutador    FOREIGN KEY (id_enrutador)     REFERENCES usuarios_sistema(id_usuario),
    CONSTRAINT fk_hr_punto        FOREIGN KEY (id_punto_entrega) REFERENCES puntos_atencion(id_punto)
) ENGINE=InnoDB COMMENT='Cargas de trabajo asignadas';

-- 9. Detalle de Hoja de Ruta
CREATE TABLE IF NOT EXISTS detalle_hoja_ruta (
    id_detalle                INT       NOT NULL AUTO_INCREMENT,
    id_hoja_ruta              INT       NOT NULL,
    id_solicitud              INT       NOT NULL,
    orden_visita              INT       NOT NULL DEFAULT 1,
    hora_confirmacion_llamada  DATETIME,
    fecha_visita              DATETIME,
    resultado                 ENUM('pendiente','realizada','ausente','reagendada')
                              NOT NULL DEFAULT 'pendiente',
    notas_encuestador         TEXT,
    CONSTRAINT pk_detalle        PRIMARY KEY (id_detalle),
    CONSTRAINT fk_det_hoja       FOREIGN KEY (id_hoja_ruta)  REFERENCES hojas_ruta(id_hoja_ruta),
    CONSTRAINT fk_det_solicitud  FOREIGN KEY (id_solicitud)  REFERENCES solicitudes_encuesta(id_solicitud),
    CONSTRAINT uq_solicitud_hoja UNIQUE (id_solicitud)
) ENGINE=InnoDB COMMENT='Detalle y resultado de visitas';

-- 10. Revisiones de Calidad
CREATE TABLE IF NOT EXISTS revisiones_calidad (
    id_revision    INT       NOT NULL AUTO_INCREMENT,
    id_solicitud   INT       NOT NULL UNIQUE,
    id_revisor     INT       NOT NULL,
    fecha_revision DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resultado      ENUM('apto','no_apto') NOT NULL,
    observaciones  TEXT,
    CONSTRAINT pk_revisiones    PRIMARY KEY (id_revision),
    CONSTRAINT fk_rev_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitudes_encuesta(id_solicitud),
    CONSTRAINT fk_rev_revisor   FOREIGN KEY (id_revisor)   REFERENCES usuarios_sistema(id_usuario)
) ENGINE=InnoDB COMMENT='Control de calidad para prestamos internacionales';

-- 11. Auditoría
CREATE TABLE IF NOT EXISTS auditoria_log (
    id_log           BIGINT       NOT NULL AUTO_INCREMENT,
    id_usuario       INT,
    accion           VARCHAR(100) NOT NULL,
    tabla_afectada   VARCHAR(50)  NOT NULL,
    registro_id      INT,
    datos_anteriores JSON,
    datos_nuevos     JSON,
    ip_address       VARCHAR(45),
    user_agent       VARCHAR(255),
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_auditoria PRIMARY KEY (id_log),
    CONSTRAINT fk_aud_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios_sistema(id_usuario)
        ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Bitácora de seguridad y auditoría';

-- Vista DDL: Semáforo de Solicitudes y SLA
CREATE OR REPLACE VIEW v_solicitudes_activas AS
SELECT
    s.id_solicitud,
    s.numero_radicado,
    s.fecha_radicacion,
    s.fecha_limite,
    DATEDIFF(s.fecha_limite, CURDATE()) AS dias_restantes,
    CASE
        WHEN DATEDIFF(s.fecha_limite, CURDATE()) < 0  THEN 'VENCIDA'
        WHEN DATEDIFF(s.fecha_limite, CURDATE()) <= 3 THEN 'CRITICA'
        WHEN DATEDIFF(s.fecha_limite, CURDATE()) <= 7 THEN 'ALERTA'
        ELSE 'NORMAL'
    END AS semaforo,
    s.estado,
    s.latitud,
    s.longitud,
    c.nombres,
    c.apellidos,
    c.celular,
    c.direccion,
    b.nombre_barrio,
    com.numero_comuna,
    com.nombre_comuna,
    p.nombre_punto AS punto_radicacion,
    CONCAT(u.nombre,' ',u.apellido) AS ventanilla_responsable
FROM solicitudes_encuesta s
INNER JOIN ciudadanos c          ON s.id_ciudadano          = c.id_ciudadano
INNER JOIN barrios b             ON c.id_barrio             = b.id_barrio
INNER JOIN comunas com           ON c.id_comuna             = com.id_comuna
INNER JOIN puntos_atencion p     ON s.id_punto_atencion     = p.id_punto
INNER JOIN usuarios_sistema u    ON s.id_usuario_ventanilla = u.id_usuario;
