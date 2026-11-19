-- ============================================================
-- ARCHIVO: 02_DML_datos_iniciales.sql
-- DESCRIPCIÓN: Data Manipulation Language — Datos semilla
-- PROYECTO: Sistema de Gestión de Encuestas (Cali)
-- ============================================================

USE encuestas_db;

-- 1. Insertar Perfiles
INSERT INTO perfiles (id_perfil, nombre_perfil, descripcion) VALUES
(1, 'administrador', 'Acceso total, gestion de usuarios, reportes PDF y dashboard'),
(2, 'ventanilla',    'Atencion al ciudadano, radicacion y georreferenciacion en mapa'),
(3, 'enrutador',     'Bitacora general de solicitudes, agrupacion y generacion de hojas de ruta'),
(4, 'encuestador',   'Recepcion de hoja de ruta y diligenciamiento de visitas en territorio'),
(5, 'calidad',       'Auditoria y validacion de encuestas (Apto / No Apto) para prestamos')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

-- 2. Puntos de Atención Fijos (Requerimiento 5: Comunas 1, 6, 7, 13, 14, 20, 21 y 17 oficina principal)
INSERT INTO puntos_atencion (id_punto, nombre_punto, numero_comuna, nombre_comuna, direccion, es_oficina_principal) VALUES
(1, 'Oficina Principal - Gran Limonar', 17, 'El Gran Limonar', 'Cra 50 # 5-75, San Fernando', 1),
(2, 'Punto Terron Colorado',           1,  'Terron Colorado', 'Cra 1 # 68-20, Terron Colorado', 0),
(3, 'Punto Doce de Octubre',           6,  'Doce de Octubre', 'Cll 44 # 26-10, Doce de Octubre', 0),
(4, 'Punto Marroquin',                 7,  'Marroquin',       'Cra 25 # 72-15, Marroquin', 0),
(5, 'Punto El Poblado',                13, 'El Poblado',      'Cra 40 # 46A-30, El Poblado', 0),
(6, 'Punto El Vallado',                14, 'El Vallado',      'Cll 56 # 42-18, El Vallado', 0),
(7, 'Punto Ciudad Cordoba',            20, 'Ciudad Cordoba',  'Cll 72 # 36-45, Ciudad Cordoba', 0),
(8, 'Punto Aguablanca',                21, 'Aguablanca',      'Cra 44 # 62-20, Aguablanca', 0)
ON DUPLICATE KEY UPDATE direccion=VALUES(direccion);

-- 3. Comunas de Cali
INSERT INTO comunas (numero_comuna, nombre_comuna, tipo) VALUES
(1,  'Terron Colorado',     'urbana'),
(2,  'Santa Rosa',          'urbana'),
(3,  'Libertadores',        'urbana'),
(4,  'Flores',              'urbana'),
(5,  'Guabal',              'urbana'),
(6,  'Doce de Octubre',     'urbana'),
(7,  'Marroquin',           'urbana'),
(8,  'Villanueva',          'urbana'),
(9,  'Los Cardales',        'urbana'),
(10, 'Calipso',             'urbana'),
(11, 'El Aguacatal',        'urbana'),
(12, 'El Cali',             'urbana'),
(13, 'El Poblado',          'urbana'),
(14, 'El Vallado',          'urbana'),
(15, 'Calipso 2',           'urbana'),
(16, 'El Lido',             'urbana'),
(17, 'El Gran Limonar',     'urbana'),
(18, 'El Ingenio',          'urbana'),
(19, 'El Caney',            'urbana'),
(20, 'Ciudad Cordoba',      'urbana'),
(21, 'Aguablanca',          'urbana'),
(22, 'Pance (Corregimiento)','rural')
ON DUPLICATE KEY UPDATE tipo=VALUES(tipo);

-- Comuna comodín para barrios nuevos mientras el campo Comuna está deshabilitado en ventanilla
INSERT IGNORE INTO comunas (numero_comuna, nombre_comuna, tipo) VALUES (0, 'Por asignar', 'urbana');

-- 4. Barrios de Cali
INSERT INTO barrios (nombre_barrio, id_comuna) VALUES
('Terron Colorado', 1), ('Los Chorros', 1), ('La Sultana', 1),
('Doce de Octubre', 6), ('Alferez Real', 6),
('Marroquin I', 7), ('Marroquin II', 7), ('Llano Verde', 7),
('El Poblado I', 13), ('El Poblado II', 13),
('El Vallado', 14), ('Mojica', 14),
('Ciudad Cordoba', 20), ('La Colina', 20),
('El Diamante', 21), ('La Paz', 21),
('San Fernando', 17), ('Limonar', 17),
('Pance Cabecera', 22), ('La Viga', 22);

-- 5. Usuarios del Sistema
-- CONTRASEÑA DE TODOS: "password"
-- Hash Bcrypt generado con PHP: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
INSERT INTO usuarios_sistema (id_usuario, nombre, apellido, email, username, password_hash, id_perfil, id_punto_atencion, telefono, activo) VALUES
(1, 'Carlos',  'Rodriguez', 'admin@encuestas.com',       'admin',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1, '3101234567', 1),
(2, 'Maria',   'Lopez',     'ventanilla@encuestas.com',  'ventanilla',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 1, '3119876543', 1),
(3, 'Pedro',   'Garcia',    'enrutador@encuestas.com',   'enrutador',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, 1, '3124567890', 1),
(4, 'Luisa',   'Martinez',  'encuestador@encuestas.com', 'encuestador', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 4, 1, '3157891234', 1),
(5, 'Andres',  'Perez',     'calidad@encuestas.com',     'calidad',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 5, 1, '3166549870', 1)
ON DUPLICATE KEY UPDATE activo=1;

-- 6. Ciudadanos de Ejemplo
INSERT INTO ciudadanos (id_ciudadano, tipo_documento, numero_documento, nombres, apellidos, celular, email, direccion, id_barrio, id_comuna) VALUES
(1, 'CC', '1144123456', 'Ana Maria',    'Torres Ruiz',      '3109876543', 'ana.torres@gmail.com',     'Cll 5 # 20-10, San Fernando', 17, 17),
(2, 'CC', '1144987654', 'Jhon Alexander','Valencia Castro', '3156543210', 'jhon.valencia@yahoo.com', 'Cra 28 # 72U-14, Marroquin II', 7, 7),
(3, 'CC', '29876543',   'Luz Dary',     'Caicedo Mina',     '3174561234', 'luz.caicedo@hotmail.com',  'Cll 57 # 41B-22, El Vallado',  11, 14),
(4, 'TI', '1006543219', 'Camilo Andres','Ospina Duque',     '3201239874', 'camilo.ospina@gmail.com',  'Cra 1A # 69-45, Terron',        1, 1)
ON DUPLICATE KEY UPDATE celular=VALUES(celular);

-- 7. Solicitudes de Encuesta de Ejemplo
INSERT INTO solicitudes_encuesta (id_solicitud, numero_radicado, id_ciudadano, id_punto_atencion, id_usuario_ventanilla, fecha_radicacion, fecha_limite, latitud, longitud, estado, observaciones) VALUES
(1, CONCAT('RAD-', DATE_FORMAT(NOW(),'%Y%m%d'), '-0001'), 1, 1, 2, NOW(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 3.42850000, -76.54120000, 'pendiente', 'Usuario solicita encuesta para subsidio de mejoramiento de vivienda.'),
(2, CONCAT('RAD-', DATE_FORMAT(NOW(),'%Y%m%d'), '-0002'), 2, 4, 2, NOW(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 3.41500000, -76.49500000, 'pendiente', 'Disponibilidad unicamente en la jornada de la manana.'),
(3, CONCAT('RAD-', DATE_FORMAT(NOW(),'%Y%m%d'), '-0003'), 3, 6, 2, NOW(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 3.40890000, -76.53120000, 'pendiente', 'Casa esquinera reja blanca.')
ON DUPLICATE KEY UPDATE estado=VALUES(estado);
