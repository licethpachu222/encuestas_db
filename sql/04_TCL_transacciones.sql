-- ============================================================
-- ARCHIVO: 04_TCL_transacciones.sql
-- DESCRIPCIÓN: Transaction Control Language — Transacciones y Procedimientos
-- PROYECTO: Sistema de Gestión de Encuestas (Cali)
-- ============================================================

USE encuestas_db;

-- Procedimiento Almacenado Transaccional: Asignación de Hojas de Ruta
DROP PROCEDURE IF EXISTS sp_generar_hoja_ruta;

DELIMITER //
CREATE PROCEDURE sp_generar_hoja_ruta(
    IN  p_id_encuestador  INT,
    IN  p_id_enrutador    INT,
    IN  p_id_punto        INT,
    IN  p_fecha_trabajo   DATE,
    IN  p_zona_tipo       ENUM('urbana','rural'),
    OUT p_resultado       VARCHAR(250)
)
BEGIN
    DECLARE v_id_hoja    INT DEFAULT 0;
    DECLARE v_total      INT DEFAULT 0;
    DECLARE v_max_enc    INT DEFAULT 20;

    -- Manejador de excepciones SQL con ROLLBACK
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_resultado = 'ERROR: Transacción abortada y revertida (ROLLBACK) debido a fallo de integridad.';
    END;

    IF p_zona_tipo = 'rural' THEN
        SET v_max_enc = 10;
    END IF;

    -- Inicio de bloque transaccional
    START TRANSACTION;

        -- Paso 1: Insertar cabecera de la hoja de ruta
        INSERT INTO hojas_ruta (id_encuestador, id_enrutador, id_punto_entrega, fecha_trabajo, zona_tipo, total_encuestas, estado)
        VALUES (p_id_encuestador, p_id_enrutador, p_id_punto, p_fecha_trabajo, p_zona_tipo, 0, 'generada');
        
        SET v_id_hoja = LAST_INSERT_ID();

        -- Paso 2: Asignar encuestas más urgentes (priorizando menor fecha_limite)
        INSERT INTO detalle_hoja_ruta (id_hoja_ruta, id_solicitud, orden_visita, resultado)
        SELECT
            v_id_hoja,
            s.id_solicitud,
            ROW_NUMBER() OVER (ORDER BY s.fecha_limite ASC, s.fecha_radicacion ASC),
            'pendiente'
        FROM solicitudes_encuesta s
        INNER JOIN ciudadanos c ON s.id_ciudadano = c.id_ciudadano
        INNER JOIN comunas com  ON c.id_comuna = com.id_comuna
        WHERE s.estado = 'pendiente'
          AND com.tipo = p_zona_tipo
        LIMIT v_max_enc;

        SET v_total = ROW_COUNT();

        -- Paso 3: Actualizar contador en la cabecera
        UPDATE hojas_ruta SET total_encuestas = v_total WHERE id_hoja_ruta = v_id_hoja;

        -- Paso 4: Cambiar estado a 'asignada' para evitar duplicidad de ruteo
        UPDATE solicitudes_encuesta
        SET estado = 'asignada'
        WHERE id_solicitud IN (
            SELECT id_solicitud FROM detalle_hoja_ruta WHERE id_hoja_ruta = v_id_hoja
        );

    -- Confirmación atómica de todos los cambios
    COMMIT;

    SET p_resultado = CONCAT('OK: Hoja de ruta #', v_id_hoja, ' generada exitosamente con ', v_total, ' solicitudes.');
END //
DELIMITER ;
