-- =====================================================================
-- CP BancaVirtual - Limpieza de clientes para comenzar de nuevo (coordinada con CP Gestiones)
-- Borra TODOS los clientes (rol CLIENTE) y todo lo que depende de ellos: cuentas, movimientos,
-- transferencias, tarjetas, préstamos, pagos de servicios, solicitudes, notificaciones, sesiones…
-- Conserva: estructura, catálogo de servicios, administradores (rol ADMIN) y sus sesiones.
-- Correr UNA vez, después de reiniciar los datos en CP Gestiones. Todo o nada (transacción).
-- =====================================================================
BEGIN;

DELETE FROM abonos_externos;
DELETE FROM transferencias;
DELETE FROM transferencias_programadas;
DELETE FROM pagos_servicios;
DELETE FROM prestamo_cuotas;
DELETE FROM prestamos;
DELETE FROM tarjeta_movimientos;
DELETE FROM tarjetas;
DELETE FROM movimientos;
DELETE FROM cuentas;
DELETE FROM solicitudes;
DELETE FROM beneficiarios;
DELETE FROM notificaciones WHERE cliente_id IN (SELECT id FROM clientes WHERE rol = 'CLIENTE');
DELETE FROM sesiones WHERE cliente_id IN (SELECT id FROM clientes WHERE rol = 'CLIENTE');
DELETE FROM push_suscripciones WHERE cliente_id IN (SELECT id FROM clientes WHERE rol = 'CLIENTE');
DELETE FROM clientes WHERE rol = 'CLIENTE';

COMMIT;

-- Comprobación: todo en 0 (excepto administradores)
SELECT (SELECT count(*) FROM clientes WHERE rol = 'CLIENTE') AS clientes, (SELECT count(*) FROM cuentas) AS cuentas,
       (SELECT count(*) FROM movimientos) AS movimientos, (SELECT count(*) FROM tarjetas) AS tarjetas, (SELECT count(*) FROM prestamos) AS prestamos;
