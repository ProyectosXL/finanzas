/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: EXCLUIR UNA FACTURA DEL CASHFLOW
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales_forma_por_factura.sql, que es
             el que deja FECHA_PAGO nullable.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Se podia excluir a un PROVEEDOR -clasificandolo en el rubro "Excluidos" del
   maestro- pero no a una FACTURA suelta. Una factura duplicada, una en disputa
   o una que se pago por fuera de Tango entraban igual al cronograma y no habia
   donde decir que no.

   ----------------------------------------------------------------------------
   DONDE VA EL IMPORTE, Y POR QUE NO ALCANZABA CON PAGOS_EXCLUIDOS

   El proveedor del tablero reparte cada vencimiento en DOS PARTICIONES
   independientes del mismo universo:

       por COMO se paga    PAGOS + PAGOS_FUERA_CRONOGRAMA       = PAGOS_TODO
       por SI esta excluido  PAGOS_OPERATIVOS + PAGOS_EXCLUIDOS = PAGOS_TODO

   La fila del tablero usa PAGOS, que es el primer corte: PAGOS NO EXCLUYE NADA.
   Verificado contra la base al escribir esto: la fila esta configurada con
   ORIGEN_SERIE = 'PAGOS', y ahi adentro hay $72.500.993,87 en 8 vencimientos de
   un proveedor con rubro "Excluidos" que igual entran, porque se le paga por
   echeq.

   O sea que mandar la factura tildada solo a PAGOS_EXCLUIDOS no la habria sacado
   del cashflow: la habria sacado de una serie que hoy no usa nadie.

   Por eso la exclusion manual sale TAMBIEN del primer corte, con serie propia:

       PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA = PAGOS_TODO

   Las dos particiones siguen cerrando contra PAGOS_TODO, que es lo que
   tests/test_proveedores.php verifica contra los datos reales. El importe no
   desaparece: queda en su propia serie, visible y auditable, y el proveedor
   avisa cuanto es.

   NO SE TOCA A QUE SERIE APUNTA LA FILA DEL TABLERO. Repuntarla a
   PAGOS_CRONO_OPERATIVOS es otra decision -saca ademas a los socios- y se hace
   desde Parametros cuando se quiera.

   ----------------------------------------------------------------------------
   EL MOTIVO ES OBLIGATORIO

   Lo valida el back, no la pantalla. Una factura sacada del cashflow sin motivo
   no la explica nadie tres meses despues, y este modulo esta construido sobre
   que nada desaparezca sin decir por que. Es la misma razon por la que el filtro
   por forma de pago dice cuanto esconde.

   ----------------------------------------------------------------------------
   LAS FILAS QUE YA ESTAN

   Quedan con EXCLUIDA = 0: el dia que se corra esto el tablero no se mueve ni un
   peso.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_PROV_LOCALES_PAGO. Corre primero sql/cashflow_prov_locales.sql.', 16, 1);
END
GO

IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO')
      AND name = 'FECHA_PAGO'
      AND is_nullable = 0
)
BEGIN
    RAISERROR('Corre primero sql/cashflow_prov_locales_forma_por_factura.sql: FECHA_PAGO tiene que poder ser NULL para guardar una exclusion sin fecha de pago.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. El tilde.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'EXCLUIDA') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ADD EXCLUIDA BIT NOT NULL
            CONSTRAINT DF_RO_T_CF_PROVPAGO_EXCLUIDA DEFAULT (0);
END
GO

/* ----------------------------------------------------------------------------
   2. El motivo. Es obligatorio de negocio -lo valida Proveedores::saveExclusion()-
      pero la columna es NULL: una fila que no esta excluida no tiene motivo que
      guardar, y un '' obligatorio seria un motivo vacio haciendose pasar por uno.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'MOTIVO_EXCLUSION') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ADD MOTIVO_EXCLUSION VARCHAR(200) NULL;
END
GO

/* ----------------------------------------------------------------------------
   3. Control: no puede haber una factura excluida sin motivo.

   Si esto devuelve filas, alguien tildo por fuera de la pantalla. No rompe nada
   -el importe sale del cashflow igual- pero nadie va a poder explicar por que.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
    WHERE EXCLUIDA = 1
      AND (MOTIVO_EXCLUSION IS NULL OR LTRIM(RTRIM(MOTIVO_EXCLUSION)) = '')
)
BEGIN
    RAISERROR('ATENCION: hay facturas excluidas sin motivo. Revisalas: el importe sale del cashflow y nadie va a poder explicar por que.', 16, 1);
END
GO

PRINT 'Exclusion de facturas lista.';
GO
