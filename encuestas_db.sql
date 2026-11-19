-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 19-11-2026 a las 20:05:11
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `encuestas_db`
--

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_generar_hoja_ruta` (IN `p_id_encuestador` INT, IN `p_id_enrutador` INT, IN `p_id_punto` INT, IN `p_fecha_trabajo` DATE, IN `p_zona_tipo` ENUM('urbana','rural'), OUT `p_resultado` VARCHAR(250))   BEGIN
    DECLARE v_id_hoja    INT DEFAULT 0;
    DECLARE v_total      INT DEFAULT 0;
    DECLARE v_max_enc    INT DEFAULT 20;

    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_resultado = 'ERROR: Transacción abortada y revertida (ROLLBACK) debido a fallo de integridad.';
    END;

    IF p_zona_tipo = 'rural' THEN
        SET v_max_enc = 10;
    END IF;

    
    START TRANSACTION;

       
        INSERT INTO hojas_ruta (id_encuestador, id_enrutador, id_punto_entrega, fecha_trabajo, zona_tipo, total_encuestas, estado)
        VALUES (p_id_encuestador, p_id_enrutador, p_id_punto, p_fecha_trabajo, p_zona_tipo, 0, 'generada');
        
        SET v_id_hoja = LAST_INSERT_ID();

        
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

       
        UPDATE hojas_ruta SET total_encuestas = v_total WHERE id_hoja_ruta = v_id_hoja;

       
        UPDATE solicitudes_encuesta
        SET estado = 'asignada'
        WHERE id_solicitud IN (
            SELECT id_solicitud FROM detalle_hoja_ruta WHERE id_hoja_ruta = v_id_hoja
        );

   
    COMMIT;

    SET p_resultado = CONCAT('OK: Hoja de ruta #', v_id_hoja, ' generada exitosamente con ', v_total, ' solicitudes.');
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_registrar_solicitud` (IN `p_id_tipo_documento` TINYINT UNSIGNED, IN `p_numero_documento` VARCHAR(20), IN `p_nombres` VARCHAR(80), IN `p_apellidos` VARCHAR(80), IN `p_celular` VARCHAR(15), IN `p_direccion` VARCHAR(150), IN `p_id_barrio` INT UNSIGNED, IN `p_id_usuario` INT UNSIGNED, IN `p_id_punto` TINYINT UNSIGNED, IN `p_latitud` DECIMAL(9,6), IN `p_longitud` DECIMAL(9,6))   BEGIN
  DECLARE v_id_solicitante INT UNSIGNED;
  DECLARE v_id_solicitud   INT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  SELECT id_solicitante INTO v_id_solicitante
  FROM solicitante
  WHERE id_tipo_documento = p_id_tipo_documento
    AND numero_documento = p_numero_documento
  LIMIT 1;

  IF v_id_solicitante IS NULL THEN
    INSERT INTO solicitante
      (id_tipo_documento, numero_documento, nombres, apellidos, celular, direccion, id_barrio)
    VALUES
      (p_id_tipo_documento, p_numero_documento, p_nombres, p_apellidos, p_celular, p_direccion, p_id_barrio);
    SET v_id_solicitante = LAST_INSERT_ID();
  ELSE
    UPDATE solicitante
    SET nombres = p_nombres, apellidos = p_apellidos, celular = p_celular,
        direccion = p_direccion, id_barrio = p_id_barrio
    WHERE id_solicitante = v_id_solicitante;
  END IF;

  INSERT INTO solicitud_encuesta
    (id_solicitante, id_usuario, id_punto_atencion, fecha_limite, latitud, longitud)
  VALUES
    (v_id_solicitante, p_id_usuario, p_id_punto, DATE_ADD(CURDATE(), INTERVAL 15 DAY), p_latitud, p_longitud);
  SET v_id_solicitud = LAST_INSERT_ID();

  COMMIT;

  SELECT v_id_solicitud AS id_solicitud;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `auditoria_log`
--

CREATE TABLE `auditoria_log` (
  `id_log` bigint(20) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `tabla_afectada` varchar(50) NOT NULL,
  `registro_id` int(11) DEFAULT NULL,
  `datos_anteriores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos_anteriores`)),
  `datos_nuevos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos_nuevos`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bitácora de seguridad y auditoría';

--
-- Volcado de datos para la tabla `auditoria_log`
--

INSERT INTO `auditoria_log` (`id_log`, `id_usuario`, `accion`, `tabla_afectada`, `registro_id`, `datos_anteriores`, `datos_nuevos`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'LOGIN_EXITOSO', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 18:45:48'),
(2, 1, 'LOGIN_EXITOSO', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:39:38'),
(3, 1, 'LOGOUT', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:42:04'),
(4, 1, 'LOGIN_EXITOSO', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:42:30'),
(5, 1, 'GENERAR_HOJA_RUTA', 'hojas_ruta', 1, NULL, '{\"encuestador\":4,\"fecha\":\"2026-11-10\",\"zona\":\"urbana\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:44:00'),
(6, 1, 'LOGOUT', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:46:22'),
(7, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:46:50'),
(8, 2, 'RADICACION_SOLICITUD', 'solicitudes_encuesta', 4, NULL, '{\"radicado\":\"RAD-20261109-0004\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:56:48'),
(9, 2, 'LOGOUT', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:57:59'),
(10, 3, 'LOGIN_EXITOSO', 'usuarios_sistema', 3, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:58:22'),
(11, 3, 'LOGOUT', 'usuarios_sistema', 3, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:59:45'),
(12, 4, 'LOGIN_EXITOSO', 'usuarios_sistema', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 19:59:59'),
(13, 4, 'LOGOUT', 'usuarios_sistema', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 20:01:21'),
(14, 5, 'LOGIN_EXITOSO', 'usuarios_sistema', 5, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-09 20:01:40'),
(15, 1, 'LOGIN_EXITOSO', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-11 22:01:08'),
(16, 1, 'ENTREGAR_HOJA_RUTA', 'hojas_ruta', 1, '{\"estado\":\"generada\"}', '{\"estado\":\"entregada\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-11 22:03:28'),
(17, 1, 'LOGOUT', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-11 22:06:43'),
(18, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-11 22:07:00'),
(19, 1, 'LOGIN_EXITOSO', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 00:41:52'),
(20, 1, 'LOGOUT', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 00:42:07'),
(21, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 00:42:17'),
(22, 2, 'LOGOUT', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 00:56:16'),
(23, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 00:57:14'),
(24, 2, 'LOGOUT', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:04:38'),
(25, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:05:41'),
(26, 2, 'LOGOUT', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:40:34'),
(27, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:42:52'),
(28, 2, 'LOGOUT', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:45:48'),
(29, 5, 'LOGIN_EXITOSO', 'usuarios_sistema', 5, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:46:01'),
(30, 5, 'LOGOUT', 'usuarios_sistema', 5, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:46:27'),
(31, 4, 'LOGIN_EXITOSO', 'usuarios_sistema', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:46:40'),
(32, 4, 'REGISTRAR_VISITA', 'detalle_hoja_ruta', 1, '{\"resultado\":\"pendiente\"}', '{\"resultado\":\"realizada\",\"notas\":\"\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:47:14'),
(33, 4, 'LOGOUT', 'usuarios_sistema', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:47:57'),
(34, 5, 'LOGIN_EXITOSO', 'usuarios_sistema', 5, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:48:27'),
(35, 5, 'LOGOUT', 'usuarios_sistema', 5, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:50:29'),
(36, 1, 'LOGIN_EXITOSO', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 01:50:38'),
(37, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 03:02:02'),
(38, 2, 'RADICACION_SOLICITUD', 'solicitudes_encuesta', 5, NULL, '{\"radicado\":\"RAD-20261111-0001\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 03:03:34'),
(39, 2, 'CORRECCION_DIRECCION', 'solicitudes_encuesta', 5, '{\"dir_anterior\":\"Cl. 38 Nte #6N-45\",\"lat_ant\":\"3.47653590\",\"lng_ant\":\"-76.52815270\"}', '{\"dir_nueva\":\"Cl. 38 Nte #6N-45\",\"lat_nueva\":3.4765182,\"lng_nueva\":-76.5282404}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 03:07:25'),
(40, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-12 23:16:56'),
(41, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-13 00:30:06'),
(42, 2, 'LOGOUT', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-13 00:33:48'),
(43, 2, 'LOGIN_EXITOSO', 'usuarios_sistema', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-13 00:33:58'),
(44, 1, 'LOGIN_EXITOSO', 'usuarios_sistema', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '2026-11-19 14:29:18');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `barrios`
--

CREATE TABLE `barrios` (
  `id_barrio` int(11) NOT NULL,
  `nombre_barrio` varchar(100) NOT NULL,
  `id_comuna` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Barrios de Cali asociados a comunas';

--
-- Volcado de datos para la tabla `barrios`
--

INSERT INTO `barrios` (`id_barrio`, `nombre_barrio`, `id_comuna`) VALUES
(1, 'Terron Colorado', 1),
(2, 'Los Chorros', 1),
(3, 'La Sultana', 1),
(4, 'Doce de Octubre', 6),
(5, 'Alferez Real', 6),
(6, 'Marroquin I', 7),
(7, 'Marroquin II', 7),
(8, 'Llano Verde', 7),
(9, 'El Poblado I', 13),
(10, 'El Poblado II', 13),
(11, 'El Vallado', 14),
(12, 'Mojica', 14),
(13, 'Ciudad Cordoba', 20),
(14, 'La Colina', 20),
(15, 'El Diamante', 21),
(16, 'La Paz', 21),
(17, 'San Fernando', 17),
(18, 'Limonar', 17),
(19, 'Pance Cabecera', 22),
(20, 'La Viga', 22),
(21, 'Chipichape', 23);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ciudadanos`
--

CREATE TABLE `ciudadanos` (
  `id_ciudadano` int(11) NOT NULL,
  `tipo_documento` enum('CC','CE','TI','PP','NIT') NOT NULL,
  `numero_documento` varchar(20) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `celular` varchar(15) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `direccion` varchar(200) NOT NULL,
  `id_barrio` int(11) NOT NULL,
  `id_comuna` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ciudadanos que solicitan encuestas domiciliarias';

--
-- Volcado de datos para la tabla `ciudadanos`
--

INSERT INTO `ciudadanos` (`id_ciudadano`, `tipo_documento`, `numero_documento`, `nombres`, `apellidos`, `celular`, `email`, `fecha_nacimiento`, `direccion`, `id_barrio`, `id_comuna`, `created_at`) VALUES
(1, 'CC', '1144123456', 'Ana Maria', 'Torres Ruiz', '3109876543', 'ana.torres@gmail.com', NULL, 'Cll 5 # 20-10, San Fernando', 17, 17, '2026-11-09 18:16:06'),
(2, 'CC', '1144987654', 'Jhon Alexander', 'Valencia Castro', '3156543210', 'jhon.valencia@yahoo.com', NULL, 'Cra 28 # 72U-14, Marroquin II', 7, 7, '2026-11-09 18:16:06'),
(3, 'CC', '29876543', 'Luz Dary', 'Caicedo Mina', '3174561234', 'luz.caicedo@hotmail.com', NULL, 'Cll 57 # 41B-22, El Vallado', 11, 14, '2026-11-09 18:16:06'),
(4, 'TI', '1006543219', 'Camilo Andres', 'Ospina Duque', '3201239874', 'camilo.ospina@gmail.com', NULL, 'Cra 1A # 69-45, Terron', 1, 1, '2026-11-09 18:16:06'),
(5, 'CC', 'TGNHMNHMJ', 'Camila', 'Porras', '3125678934', 'cporras.@gmail.com', NULL, 'Cra. 98 #18-49', 17, 17, '2026-11-09 19:56:48'),
(6, 'CC', '10093458923', 'julian', 'patiño', '3210984523', 'julian.patino@gmail.com', NULL, 'Cl. 38 Nte #6N-45', 21, 23, '2026-11-12 03:03:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comunas`
--

CREATE TABLE `comunas` (
  `id_comuna` int(11) NOT NULL,
  `numero_comuna` int(11) NOT NULL,
  `nombre_comuna` varchar(100) NOT NULL,
  `tipo` enum('urbana','rural') NOT NULL DEFAULT 'urbana'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='División político-administrativa de comunas y corregimientos';

--
-- Volcado de datos para la tabla `comunas`
--

INSERT INTO `comunas` (`id_comuna`, `numero_comuna`, `nombre_comuna`, `tipo`) VALUES
(1, 1, 'Terron Colorado', 'urbana'),
(2, 2, 'Santa Rosa', 'urbana'),
(3, 3, 'Libertadores', 'urbana'),
(4, 4, 'Flores', 'urbana'),
(5, 5, 'Guabal', 'urbana'),
(6, 6, 'Doce de Octubre', 'urbana'),
(7, 7, 'Marroquin', 'urbana'),
(8, 8, 'Villanueva', 'urbana'),
(9, 9, 'Los Cardales', 'urbana'),
(10, 10, 'Calipso', 'urbana'),
(11, 11, 'El Aguacatal', 'urbana'),
(12, 12, 'El Cali', 'urbana'),
(13, 13, 'El Poblado', 'urbana'),
(14, 14, 'El Vallado', 'urbana'),
(15, 15, 'Calipso 2', 'urbana'),
(16, 16, 'El Lido', 'urbana'),
(17, 17, 'El Gran Limonar', 'urbana'),
(18, 18, 'El Ingenio', 'urbana'),
(19, 19, 'El Caney', 'urbana'),
(20, 20, 'Ciudad Cordoba', 'urbana'),
(21, 21, 'Aguablanca', 'urbana'),
(22, 22, 'Pance (Corregimiento)', 'rural'),
(23, 0, 'Por asignar', 'urbana');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_hoja_ruta`
--

CREATE TABLE `detalle_hoja_ruta` (
  `id_detalle` int(11) NOT NULL,
  `id_hoja_ruta` int(11) NOT NULL,
  `id_solicitud` int(11) NOT NULL,
  `orden_visita` int(11) NOT NULL DEFAULT 1,
  `hora_confirmacion_llamada` datetime DEFAULT NULL,
  `fecha_visita` datetime DEFAULT NULL,
  `resultado` enum('pendiente','realizada','ausente','reagendada') NOT NULL DEFAULT 'pendiente',
  `notas_encuestador` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detalle y resultado de visitas';

--
-- Volcado de datos para la tabla `detalle_hoja_ruta`
--

INSERT INTO `detalle_hoja_ruta` (`id_detalle`, `id_hoja_ruta`, `id_solicitud`, `orden_visita`, `hora_confirmacion_llamada`, `fecha_visita`, `resultado`, `notas_encuestador`) VALUES
(1, 1, 1, 1, NULL, '2026-11-11 20:47:14', 'realizada', NULL),
(2, 1, 2, 2, NULL, NULL, 'pendiente', NULL),
(3, 1, 3, 3, NULL, NULL, 'pendiente', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `hojas_ruta`
--

CREATE TABLE `hojas_ruta` (
  `id_hoja_ruta` int(11) NOT NULL,
  `id_encuestador` int(11) NOT NULL,
  `id_enrutador` int(11) NOT NULL,
  `id_punto_entrega` int(11) NOT NULL,
  `fecha_asignacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_trabajo` date NOT NULL,
  `zona_tipo` enum('urbana','rural') NOT NULL DEFAULT 'urbana',
  `total_encuestas` int(11) NOT NULL DEFAULT 0,
  `estado` enum('generada','entregada','en_proceso','completada') NOT NULL DEFAULT 'generada'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cargas de trabajo asignadas';

--
-- Volcado de datos para la tabla `hojas_ruta`
--

INSERT INTO `hojas_ruta` (`id_hoja_ruta`, `id_encuestador`, `id_enrutador`, `id_punto_entrega`, `fecha_asignacion`, `fecha_trabajo`, `zona_tipo`, `total_encuestas`, `estado`) VALUES
(1, 4, 1, 1, '2026-11-09 14:44:00', '2026-11-10', 'urbana', 3, 'en_proceso');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `perfiles`
--

CREATE TABLE `perfiles` (
  `id_perfil` int(11) NOT NULL,
  `nombre_perfil` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfiles de acceso al sistema';

--
-- Volcado de datos para la tabla `perfiles`
--

INSERT INTO `perfiles` (`id_perfil`, `nombre_perfil`, `descripcion`, `created_at`) VALUES
(1, 'administrador', 'Acceso total, gestion de usuarios, reportes PDF y dashboard', '2026-11-09 18:16:06'),
(2, 'ventanilla', 'Atencion al ciudadano, radicacion y georreferenciacion en mapa', '2026-11-09 18:16:06'),
(3, 'enrutador', 'Bitacora general de solicitudes, agrupacion y generacion de hojas de ruta', '2026-11-09 18:16:06'),
(4, 'encuestador', 'Recepcion de hoja de ruta y diligenciamiento de visitas en territorio', '2026-11-09 18:16:06'),
(5, 'calidad', 'Auditoria y validacion de encuestas (Apto / No Apto) para prestamos', '2026-11-09 18:16:06');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `puntos_atencion`
--

CREATE TABLE `puntos_atencion` (
  `id_punto` int(11) NOT NULL,
  `nombre_punto` varchar(100) NOT NULL,
  `numero_comuna` int(11) NOT NULL,
  `nombre_comuna` varchar(100) NOT NULL,
  `direccion` varchar(200) DEFAULT NULL,
  `es_oficina_principal` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Puntos de atención estáticos en Cali';

--
-- Volcado de datos para la tabla `puntos_atencion`
--

INSERT INTO `puntos_atencion` (`id_punto`, `nombre_punto`, `numero_comuna`, `nombre_comuna`, `direccion`, `es_oficina_principal`, `activo`, `created_at`) VALUES
(1, 'Oficina Principal - Gran Limonar', 17, 'El Gran Limonar', 'Cra 50 # 5-75, San Fernando', 1, 1, '2026-11-09 18:16:06'),
(2, 'Punto Terron Colorado', 1, 'Terron Colorado', 'Cra 1 # 68-20, Terron Colorado', 0, 1, '2026-11-09 18:16:06'),
(3, 'Punto Doce de Octubre', 6, 'Doce de Octubre', 'Cll 44 # 26-10, Doce de Octubre', 0, 1, '2026-11-09 18:16:06'),
(4, 'Punto Marroquin', 7, 'Marroquin', 'Cra 25 # 72-15, Marroquin', 0, 1, '2026-11-09 18:16:06'),
(5, 'Punto El Poblado', 13, 'El Poblado', 'Cra 40 # 46A-30, El Poblado', 0, 1, '2026-11-09 18:16:06'),
(6, 'Punto El Vallado', 14, 'El Vallado', 'Cll 56 # 42-18, El Vallado', 0, 1, '2026-11-09 18:16:06'),
(7, 'Punto Ciudad Cordoba', 20, 'Ciudad Cordoba', 'Cll 72 # 36-45, Ciudad Cordoba', 0, 1, '2026-11-09 18:16:06'),
(8, 'Punto Aguablanca', 21, 'Aguablanca', 'Cra 44 # 62-20, Aguablanca', 0, 1, '2026-11-09 18:16:06');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `revisiones_calidad`
--

CREATE TABLE `revisiones_calidad` (
  `id_revision` int(11) NOT NULL,
  `id_solicitud` int(11) NOT NULL,
  `id_revisor` int(11) NOT NULL,
  `fecha_revision` datetime NOT NULL DEFAULT current_timestamp(),
  `resultado` enum('apto','no_apto') NOT NULL,
  `observaciones` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Control de calidad para prestamos internacionales';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitudes_encuesta`
--

CREATE TABLE `solicitudes_encuesta` (
  `id_solicitud` int(11) NOT NULL,
  `numero_radicado` varchar(20) NOT NULL,
  `id_ciudadano` int(11) NOT NULL,
  `id_punto_atencion` int(11) NOT NULL,
  `id_usuario_ventanilla` int(11) NOT NULL,
  `fecha_radicacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_limite` date NOT NULL,
  `latitud` decimal(10,8) DEFAULT NULL,
  `longitud` decimal(11,8) DEFAULT NULL,
  `estado` enum('pendiente','asignada','en_proceso','realizada','revisada','apta','no_apta') NOT NULL DEFAULT 'pendiente',
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitudes de encuesta con coordenadas y SLA legal';

--
-- Volcado de datos para la tabla `solicitudes_encuesta`
--

INSERT INTO `solicitudes_encuesta` (`id_solicitud`, `numero_radicado`, `id_ciudadano`, `id_punto_atencion`, `id_usuario_ventanilla`, `fecha_radicacion`, `fecha_limite`, `latitud`, `longitud`, `estado`, `observaciones`, `created_at`, `updated_at`) VALUES
(1, 'RAD-20261109-0001', 1, 1, 2, '2026-11-09 13:16:06', '2026-11-24', 3.42850000, -76.54120000, 'realizada', 'Usuario solicita encuesta para subsidio de mejoramiento de vivienda.', '2026-11-09 18:16:06', '2026-11-12 01:47:14'),
(2, 'RAD-20261109-0002', 2, 4, 2, '2026-11-09 13:16:06', '2026-11-24', 3.41500000, -76.49500000, 'asignada', 'Disponibilidad unicamente en la jornada de la manana.', '2026-11-09 18:16:06', '2026-11-09 19:44:00'),
(3, 'RAD-20261109-0003', 3, 6, 2, '2026-11-09 13:16:06', '2026-11-24', 3.40890000, -76.53120000, 'asignada', 'Casa esquinera reja blanca.', '2026-11-09 18:16:06', '2026-11-09 19:44:00'),
(4, 'RAD-20261109-0004', 5, 1, 2, '2026-11-09 14:56:48', '2026-11-24', 3.41211160, -76.57886260, 'pendiente', NULL, '2026-11-09 19:56:48', '2026-11-09 19:56:48'),
(5, 'RAD-20261111-0001', 6, 1, 2, '2026-11-11 22:03:34', '2026-11-26', 3.47651820, -76.52824040, 'pendiente', 'Local 5\n[2026-11-11 22:07:25 - Dir actualizada: Cl. 38 Nte #6N-45 Motivo: ]', '2026-11-12 03:03:34', '2026-11-12 03:07:25');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios_sistema`
--

CREATE TABLE `usuarios_sistema` (
  `id_usuario` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `id_perfil` int(11) NOT NULL,
  `id_punto_atencion` int(11) DEFAULT NULL,
  `telefono` varchar(15) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Usuarios de la aplicación';

--
-- Volcado de datos para la tabla `usuarios_sistema`
--

INSERT INTO `usuarios_sistema` (`id_usuario`, `nombre`, `apellido`, `email`, `username`, `password_hash`, `id_perfil`, `id_punto_atencion`, `telefono`, `activo`, `ultimo_login`, `created_at`, `updated_at`) VALUES
(1, 'Carlos', 'Rodriguez', 'admin@encuestas.com', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1, '3101234567', 1, '2026-11-19 09:29:18', '2026-11-09 18:16:06', '2026-11-19 14:29:18'),
(2, 'Maria', 'Lopez', 'ventanilla@encuestas.com', 'ventanilla', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 1, '3119876543', 1, '2026-11-12 19:33:58', '2026-11-09 18:16:06', '2026-11-13 00:33:58'),
(3, 'Pedro', 'Garcia', 'enrutador@encuestas.com', 'enrutador', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, 1, '3124567890', 1, '2026-11-09 14:58:22', '2026-11-09 18:16:06', '2026-11-09 19:58:22'),
(4, 'Luisa', 'Martinez', 'encuestador@encuestas.com', 'encuestador', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 4, 1, '3157891234', 1, '2026-11-11 20:46:40', '2026-11-09 18:16:06', '2026-11-12 01:46:40'),
(5, 'Andres', 'Perez', 'calidad@encuestas.com', 'calidad', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 5, 1, '3166549870', 1, '2026-11-11 20:48:27', '2026-11-09 18:16:06', '2026-11-12 01:48:27');

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_solicitudes_activas`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_solicitudes_activas` (
`id_solicitud` int(11)
,`numero_radicado` varchar(20)
,`fecha_radicacion` datetime
,`fecha_limite` date
,`dias_restantes` int(7)
,`semaforo` varchar(7)
,`estado` enum('pendiente','asignada','en_proceso','realizada','revisada','apta','no_apta')
,`latitud` decimal(10,8)
,`longitud` decimal(11,8)
,`nombres` varchar(100)
,`apellidos` varchar(100)
,`celular` varchar(15)
,`direccion` varchar(200)
,`nombre_barrio` varchar(100)
,`numero_comuna` int(11)
,`nombre_comuna` varchar(100)
,`punto_radicacion` varchar(100)
,`ventanilla_responsable` varchar(201)
);

-- --------------------------------------------------------

--
-- Estructura para la vista `v_solicitudes_activas`
--
DROP TABLE IF EXISTS `v_solicitudes_activas`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_solicitudes_activas`  AS SELECT `s`.`id_solicitud` AS `id_solicitud`, `s`.`numero_radicado` AS `numero_radicado`, `s`.`fecha_radicacion` AS `fecha_radicacion`, `s`.`fecha_limite` AS `fecha_limite`, to_days(`s`.`fecha_limite`) - to_days(curdate()) AS `dias_restantes`, CASE WHEN to_days(`s`.`fecha_limite`) - to_days(curdate()) < 0 THEN 'VENCIDA' WHEN to_days(`s`.`fecha_limite`) - to_days(curdate()) <= 3 THEN 'CRITICA' WHEN to_days(`s`.`fecha_limite`) - to_days(curdate()) <= 7 THEN 'ALERTA' ELSE 'NORMAL' END AS `semaforo`, `s`.`estado` AS `estado`, `s`.`latitud` AS `latitud`, `s`.`longitud` AS `longitud`, `c`.`nombres` AS `nombres`, `c`.`apellidos` AS `apellidos`, `c`.`celular` AS `celular`, `c`.`direccion` AS `direccion`, `b`.`nombre_barrio` AS `nombre_barrio`, `com`.`numero_comuna` AS `numero_comuna`, `com`.`nombre_comuna` AS `nombre_comuna`, `p`.`nombre_punto` AS `punto_radicacion`, concat(`u`.`nombre`,' ',`u`.`apellido`) AS `ventanilla_responsable` FROM (((((`solicitudes_encuesta` `s` join `ciudadanos` `c` on(`s`.`id_ciudadano` = `c`.`id_ciudadano`)) join `barrios` `b` on(`c`.`id_barrio` = `b`.`id_barrio`)) join `comunas` `com` on(`c`.`id_comuna` = `com`.`id_comuna`)) join `puntos_atencion` `p` on(`s`.`id_punto_atencion` = `p`.`id_punto`)) join `usuarios_sistema` `u` on(`s`.`id_usuario_ventanilla` = `u`.`id_usuario`)) ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `auditoria_log`
--
ALTER TABLE `auditoria_log`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `fk_aud_usuario` (`id_usuario`);

--
-- Indices de la tabla `barrios`
--
ALTER TABLE `barrios`
  ADD PRIMARY KEY (`id_barrio`),
  ADD KEY `fk_bar_comuna` (`id_comuna`);

--
-- Indices de la tabla `ciudadanos`
--
ALTER TABLE `ciudadanos`
  ADD PRIMARY KEY (`id_ciudadano`),
  ADD UNIQUE KEY `uq_documento` (`tipo_documento`,`numero_documento`),
  ADD KEY `fk_ciu_barrio` (`id_barrio`),
  ADD KEY `fk_ciu_comuna` (`id_comuna`);

--
-- Indices de la tabla `comunas`
--
ALTER TABLE `comunas`
  ADD PRIMARY KEY (`id_comuna`),
  ADD UNIQUE KEY `numero_comuna` (`numero_comuna`);

--
-- Indices de la tabla `detalle_hoja_ruta`
--
ALTER TABLE `detalle_hoja_ruta`
  ADD PRIMARY KEY (`id_detalle`),
  ADD UNIQUE KEY `uq_solicitud_hoja` (`id_solicitud`),
  ADD KEY `fk_det_hoja` (`id_hoja_ruta`);

--
-- Indices de la tabla `hojas_ruta`
--
ALTER TABLE `hojas_ruta`
  ADD PRIMARY KEY (`id_hoja_ruta`),
  ADD KEY `fk_hr_encuestador` (`id_encuestador`),
  ADD KEY `fk_hr_enrutador` (`id_enrutador`),
  ADD KEY `fk_hr_punto` (`id_punto_entrega`);

--
-- Indices de la tabla `perfiles`
--
ALTER TABLE `perfiles`
  ADD PRIMARY KEY (`id_perfil`),
  ADD UNIQUE KEY `nombre_perfil` (`nombre_perfil`);

--
-- Indices de la tabla `puntos_atencion`
--
ALTER TABLE `puntos_atencion`
  ADD PRIMARY KEY (`id_punto`);

--
-- Indices de la tabla `revisiones_calidad`
--
ALTER TABLE `revisiones_calidad`
  ADD PRIMARY KEY (`id_revision`),
  ADD UNIQUE KEY `id_solicitud` (`id_solicitud`),
  ADD KEY `fk_rev_revisor` (`id_revisor`);

--
-- Indices de la tabla `solicitudes_encuesta`
--
ALTER TABLE `solicitudes_encuesta`
  ADD PRIMARY KEY (`id_solicitud`),
  ADD UNIQUE KEY `numero_radicado` (`numero_radicado`),
  ADD KEY `fk_sol_ciudadano` (`id_ciudadano`),
  ADD KEY `fk_sol_punto` (`id_punto_atencion`),
  ADD KEY `fk_sol_ventanilla` (`id_usuario_ventanilla`),
  ADD KEY `idx_solicitud_estado` (`estado`),
  ADD KEY `idx_solicitud_fecha_rad` (`fecha_radicacion`),
  ADD KEY `idx_solicitud_fecha_limite` (`fecha_limite`);

--
-- Indices de la tabla `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_usr_perfil` (`id_perfil`),
  ADD KEY `fk_usr_punto` (`id_punto_atencion`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `auditoria_log`
--
ALTER TABLE `auditoria_log`
  MODIFY `id_log` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT de la tabla `barrios`
--
ALTER TABLE `barrios`
  MODIFY `id_barrio` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT de la tabla `ciudadanos`
--
ALTER TABLE `ciudadanos`
  MODIFY `id_ciudadano` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `comunas`
--
ALTER TABLE `comunas`
  MODIFY `id_comuna` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de la tabla `detalle_hoja_ruta`
--
ALTER TABLE `detalle_hoja_ruta`
  MODIFY `id_detalle` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `hojas_ruta`
--
ALTER TABLE `hojas_ruta`
  MODIFY `id_hoja_ruta` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `perfiles`
--
ALTER TABLE `perfiles`
  MODIFY `id_perfil` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `puntos_atencion`
--
ALTER TABLE `puntos_atencion`
  MODIFY `id_punto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `revisiones_calidad`
--
ALTER TABLE `revisiones_calidad`
  MODIFY `id_revision` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `solicitudes_encuesta`
--
ALTER TABLE `solicitudes_encuesta`
  MODIFY `id_solicitud` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `auditoria_log`
--
ALTER TABLE `auditoria_log`
  ADD CONSTRAINT `fk_aud_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios_sistema` (`id_usuario`) ON DELETE SET NULL;

--
-- Filtros para la tabla `barrios`
--
ALTER TABLE `barrios`
  ADD CONSTRAINT `fk_bar_comuna` FOREIGN KEY (`id_comuna`) REFERENCES `comunas` (`id_comuna`);

--
-- Filtros para la tabla `ciudadanos`
--
ALTER TABLE `ciudadanos`
  ADD CONSTRAINT `fk_ciu_barrio` FOREIGN KEY (`id_barrio`) REFERENCES `barrios` (`id_barrio`),
  ADD CONSTRAINT `fk_ciu_comuna` FOREIGN KEY (`id_comuna`) REFERENCES `comunas` (`id_comuna`);

--
-- Filtros para la tabla `detalle_hoja_ruta`
--
ALTER TABLE `detalle_hoja_ruta`
  ADD CONSTRAINT `fk_det_hoja` FOREIGN KEY (`id_hoja_ruta`) REFERENCES `hojas_ruta` (`id_hoja_ruta`),
  ADD CONSTRAINT `fk_det_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes_encuesta` (`id_solicitud`);

--
-- Filtros para la tabla `hojas_ruta`
--
ALTER TABLE `hojas_ruta`
  ADD CONSTRAINT `fk_hr_encuestador` FOREIGN KEY (`id_encuestador`) REFERENCES `usuarios_sistema` (`id_usuario`),
  ADD CONSTRAINT `fk_hr_enrutador` FOREIGN KEY (`id_enrutador`) REFERENCES `usuarios_sistema` (`id_usuario`),
  ADD CONSTRAINT `fk_hr_punto` FOREIGN KEY (`id_punto_entrega`) REFERENCES `puntos_atencion` (`id_punto`);

--
-- Filtros para la tabla `revisiones_calidad`
--
ALTER TABLE `revisiones_calidad`
  ADD CONSTRAINT `fk_rev_revisor` FOREIGN KEY (`id_revisor`) REFERENCES `usuarios_sistema` (`id_usuario`),
  ADD CONSTRAINT `fk_rev_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes_encuesta` (`id_solicitud`);

--
-- Filtros para la tabla `solicitudes_encuesta`
--
ALTER TABLE `solicitudes_encuesta`
  ADD CONSTRAINT `fk_sol_ciudadano` FOREIGN KEY (`id_ciudadano`) REFERENCES `ciudadanos` (`id_ciudadano`),
  ADD CONSTRAINT `fk_sol_punto` FOREIGN KEY (`id_punto_atencion`) REFERENCES `puntos_atencion` (`id_punto`),
  ADD CONSTRAINT `fk_sol_ventanilla` FOREIGN KEY (`id_usuario_ventanilla`) REFERENCES `usuarios_sistema` (`id_usuario`);

--
-- Filtros para la tabla `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  ADD CONSTRAINT `fk_usr_perfil` FOREIGN KEY (`id_perfil`) REFERENCES `perfiles` (`id_perfil`),
  ADD CONSTRAINT `fk_usr_punto` FOREIGN KEY (`id_punto_atencion`) REFERENCES `puntos_atencion` (`id_punto`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
