/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: DE DONDE SALIO LA FECHA DE PAGO
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales.sql. Se puede correr en
             cualquier momento; sin el, la pantalla funciona igual y avisa.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   La columna Fecha de pago de Cuentas a Pagar distingue de donde sale cada
   fecha: la tipeo una persona, vino de la planilla de pagos importada, o es el
   vencimiento de Tango porque nadie cargo nada. Las dos primeras son una fecha
   CARGADA y viven en RO_T_CASHFLOW_PROV_LOCALES_PAGO.FECHA_PAGO; la tercera no
   se guarda, se calcula al leer.

   Para separar las dos primeras ya habia una columna, ORIGEN, y NO ALCANZA:

   ORIGEN DESCRIBE LA FILA, NO LA FECHA. Esa tabla dejo de ser "las fechas de
   pago" y paso a ser "los overrides de este comprobante": la fecha, la forma
   con la que se lo trata, si se lo excluye y con que motivo. Cualquier
   escritura sobre la fila pisa ORIGEN. Una fecha que vino de la planilla
   ('ARCHIVO') pasa a figurar como 'MANUAL' el dia que alguien excluye esa
   factura o le cambia la forma, aunque la fecha siga siendo la importada.

   Al escribir esto no hay ningun caso: las 78 filas con fecha no tienen ningun
   otro override en la misma fila. Por eso el relleno de abajo es exacto hoy.
   Mañana no lo seria, y es lo que esta columna viene a evitar.

   ----------------------------------------------------------------------------
   FUENTE_FECHA SE ESCRIBE SOLO CUANDO SE ESCRIBE FECHA_PAGO

   Lo hace Proveedores::guardarPago(), que es el unico camino de escritura: si
   la escritura trae FECHA_PAGO, trae tambien FUENTE_FECHA con el mismo origen
   -'MANUAL' desde la grilla o el fechado masivo, 'ARCHIVO' desde la
   importacion-; si no la trae, no la toca. Volver al vencimiento deja las dos
   en NULL.

   NO SE LLAMA ORIGEN_FECHA, aunque es lo que describe: ese nombre ya lo usa la
   fila que arma Proveedores::getPendientes() para otra cosa -CARGADA /
   VENCIMIENTO / PLAZO / SIN_FECHA, el escalon de la jerarquia- y dos campos con
   el mismo nombre y distinto significado se terminan leyendo uno por el otro.

   ----------------------------------------------------------------------------
   SIN ESTE SCRIPT

   La grilla distingue igual MANUAL de ARCHIVO, leyendo ORIGEN, que es exacto
   hasta que alguien toque otro override de una factura con fecha importada. La
   pestana avisa que falta el script.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_PROV_LOCALES_PAGO. Corre primero sql/cashflow_prov_locales.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La columna.

   NULL cuando no hay fecha cargada: no hay nada de lo que decir de donde salio.
   El CHECK deja afuera 'CONCILIA', que ORIGEN admite pero nadie escribe: la
   conciliacion no toca FECHA_PAGO -guarda la real al lado, en FECHA_CANCELADO-,
   asi que nunca es la fuente de una fecha de pago.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'FUENTE_FECHA') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ADD FUENTE_FECHA VARCHAR(10) NULL
            CONSTRAINT CK_RO_T_CF_PLPAG_FUENTE_FECHA
                CHECK (FUENTE_FECHA IN ('MANUAL', 'ARCHIVO'));
END
GO

/* ----------------------------------------------------------------------------
   2. El relleno de las filas que ya tienen fecha.

   Desde ORIGEN, que hoy describe la fecha en todas -ver el encabezado-. Solo
   las que todavia no tienen FUENTE_FECHA: correrlo de nuevo no pisa lo que el
   circuito ya escribio despues.

   SQL dinamico porque la columna la crea el lote anterior: en el mismo lote que
   el ALTER, el UPDATE no compilaria contra una base que no la tenia.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'FUENTE_FECHA') IS NOT NULL
BEGIN
    EXEC sp_executesql N'
        UPDATE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        SET FUENTE_FECHA = ORIGEN
        WHERE FECHA_PAGO IS NOT NULL
          AND FUENTE_FECHA IS NULL
          AND ORIGEN IN (''MANUAL'', ''ARCHIVO'');

        PRINT CONCAT(''Filas con fecha rellenadas desde ORIGEN: '', @@ROWCOUNT);';
END
GO

/* ----------------------------------------------------------------------------
   3. Control: una fecha cargada sin fuente.

   Si esto devuelve filas, hay una fecha que no vino ni de la grilla ni de la
   planilla -una edicion a mano sobre la base-. No rompe nada: la pantalla la
   muestra como manual, que es lo mas prudente. Pero nadie va a poder decir de
   donde salio.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'FUENTE_FECHA') IS NOT NULL
BEGIN
    EXEC sp_executesql N'
        IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
                   WHERE FECHA_PAGO IS NOT NULL AND FUENTE_FECHA IS NULL)
            PRINT ''AVISO: hay fechas de pago sin fuente. Se muestran como manuales.'';';
END
GO

PRINT 'Fuente de la fecha de pago lista.';
GO
