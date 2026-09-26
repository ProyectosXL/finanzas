/* ============================================================================
   MODULO CASHFLOW - PARTIR COBRANZAS FR EN REAL + PROYECTADA
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ejecutar DESPUES de sql/cashflow_estructura.sql (y, si se corrio,
            despues de sql/cashflow_estructura_disponibilidades.sql)
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   Hasta ahora el tablero tenia UNA fila, COBRANZAS_FR, apuntada a la serie
   COBRANZA del proveedor COBRANZAS_FR, que es real + proyectada sumadas. En
   pantalla eso es un solo numero que mezcla dos cosas que se miran distinto:
   la cobranza comprometida (propuestas ACEPTADAS) y la estimada por PPP.

   IngresosProvider ya expone las dos series por separado -COBRANZA_REAL y
   COBRANZA_PROYECTADA- y CashflowRegistry ya declara el bloque 'componentes'
   que las relaciona con el total. O sea que la fila se parte con DATOS y no
   con codigo: es exactamente para esto que la estructura del tablero vive en
   tablas.

   NO SE BORRA NADA
   COBRANZAS_FR queda con ACTIVO = 0. Hay historico de configuracion y el
   editor de Parametros la sigue mostrando para poder reactivarla.

   POR QUE NO PUEDEN CONVIVIR LAS TRES
   El registro declara COBRANZA = [COBRANZA_REAL, COBRANZA_PROYECTADA]. El
   validador de estructura rechaza tener activos a la vez el total y sus
   componentes, porque contaria dos veces el mismo importe. Al quedar
   COBRANZAS_FR inactiva, la combinacion resultante es valida.

   DONDE VAN LAS FILAS NUEVAS
   En la MISMA seccion y alrededor del MISMO orden que la fila que reemplazan,
   resuelto leyendo la fila COBRANZAS_FR en vez de escribir la seccion a mano.
   Hace falta: en una instalacion que corrio cashflow_estructura.sql y nada mas,
   COBRANZAS_FR vive en 'INGRESOS' con ORDEN 30 -que es la ubicacion que declara
   la semilla-; si ademas se corrio cashflow_estructura_disponibilidades.sql,
   vive en 'DISPONIBILIDADES' con ORDEN 40 y la seccion 'INGRESOS' quedo
   INHABILITADA. Escribir 'INGRESOS' a mano dejaria, en ese segundo caso, dos
   filas activas en una seccion inhabilitada, que es justo lo que el validador
   rechaza.

   ES REEJECUTABLE: cada insert va dentro de un IF NOT EXISTS por CODIGO y la
   baja logica pregunta por ACTIVO = 1.
   ============================================================================ */

SET NOCOUNT ON;
GO

SET XACT_ABORT ON;

BEGIN TRANSACTION;

/* ---- 1. De donde cuelgan las filas nuevas -------------------------------- */
/* Se toma de la fila que se reemplaza. El COALESCE cubre el caso de una base
   donde COBRANZAS_FR no existe: ahi vale la ubicacion de la semilla. */
DECLARE @seccion VARCHAR(30);
DECLARE @orden   INT;

SELECT @seccion = SECCION, @orden = ORDEN
FROM dbo.RO_T_CASHFLOW_CONF_FILA
WHERE CODIGO = 'COBRANZAS_FR';

SET @seccion = COALESCE(@seccion, 'INGRESOS');
SET @orden   = COALESCE(@orden, 30);

/* ---- 2. Las dos filas nuevas --------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'COBRANZAS_FR_REAL')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('COBRANZAS_FR_REAL', 'Cobranzas Franquicias (Prop. aceptadas)',
         @seccion, 'INGRESO', 1, 'COBRANZAS_FR', 'COBRANZA_REAL', @orden, 1);
END

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'COBRANZAS_FR_PROY')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('COBRANZAS_FR_PROY', 'Cobranzas Franquicias Proyectadas',
         @seccion, 'INGRESO', 1, 'COBRANZAS_FR', 'COBRANZA_PROYECTADA', @orden + 5, 1);
END

/* ---- 3. Baja logica de la fila total ------------------------------------- */
/* Va DESPUES de los inserts, dentro de la misma transaccion: mientras la total
   siga activa junto a sus dos componentes la estructura es invalida, y asi esa
   ventana no existe para nadie que lea la tabla. */
UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET ACTIVO = 0,
    NOMBRE = 'Cobranzas Franquicias (total, reemplazada por Real y Proyectada)',
    FECHA_UPDATE = GETDATE()
WHERE CODIGO = 'COBRANZAS_FR'
  AND ACTIVO = 1;

COMMIT TRANSACTION;

PRINT 'COBRANZAS_FR partida en COBRANZAS_FR_REAL + COBRANZAS_FR_PROY.';
GO
