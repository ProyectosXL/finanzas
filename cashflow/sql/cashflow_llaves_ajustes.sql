/* ============================================================================
   MODULO CASHFLOW - LLAVES Y RENOVACIONES: AJUSTES DEL TESTEO (script 73)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_llaves.sql (66) y de
            sql/cashflow_llaves_facturas.sql (68). Si alguna de sus tablas no
            existe, esa parte se saltea y lo dice: se vuelve a correr despues.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   1. "YA PAGADA" SIN IMPORTE (RO_T_CASHFLOW_LLAVES_PAGADA.MONTO)
      El monto era solo informativo: no suma ni resta nada. Desde la solapa
      Contratos se marca sin monto, y en Parametros es opcional. MONTO pasa a
      aceptar NULL y su CHECK queda MONTO IS NULL OR MONTO > 0.

   2. COMISIONES Y FPC POR SEPARADO (RO_T_CASHFLOW_LLAVES_OPERADOR)
      El % de otros gastos (comisiones + FPC) / llave se parte en dos: el % de
      comisiones y el % de FPC, los dos sobre la llave estimada. Se agregan
      PCT_COMISIONES y PCT_FPC, vacios (= sin dato).
      PCT_OTROS NO SE MIGRA NI SE BORRA: queda en la base sin usarse, y el
      codigo deja de leerlo. Al pie se listan los operadores que lo tenian
      cargado, para que Tesoreria cargue los dos % nuevos.

   3. FACTURAS DE COMISIONES Y DE FPC (RO_T_CASHFLOW_LLAVES_FACTURA.CONCEPTO)
      Una factura marcada como llave puede ser de COMISIONES o de FPC, y
      reemplaza solo lo proyectado de su concepto. Se sigue admitiendo OTROS:
      las marcas viejas no se migran solas. Siguen reemplazando comisiones, FPC
      y escribano de su renovacion, y un aviso pide deshacerlas y volver a
      marcarlas. Al pie se informa cuantas hay.

   NO BORRA NADA y no toca ningun dato: solo columnas y CHECK.
   ES REEJECUTABLE: cada parte pregunta antes (COLUMNPROPERTY, COL_LENGTH,
   la definicion del CHECK) y lo que ya esta hecho no se repite.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ============================================================================
   1. YA PAGADA: MONTO opcional
   El CHECK viejo (MONTO > 0) usa la columna: se baja, se cambia la columna y
   se vuelve a crear, en una transaccion.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA', 'U') IS NULL
    PRINT 'Falta dbo.RO_T_CASHFLOW_LLAVES_PAGADA (script 66): se saltea «Ya pagada» sin monto.';
ELSE IF COLUMNPROPERTY(OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA'), 'MONTO', 'AllowsNull') = 1
    PRINT 'RO_T_CASHFLOW_LLAVES_PAGADA.MONTO ya acepta vacío: no se toca.';
ELSE
BEGIN
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_LLPAG_MONTO'
               AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA'))
        ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_PAGADA DROP CONSTRAINT CK_RO_T_CF_LLPAG_MONTO;

    ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_PAGADA ALTER COLUMN MONTO DECIMAL(18,2) NULL;

    COMMIT TRANSACTION;
    PRINT 'RO_T_CASHFLOW_LLAVES_PAGADA.MONTO acepta vacío.';
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_LLPAG_MONTO'
                   AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA'))
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_PAGADA
        ADD CONSTRAINT CK_RO_T_CF_LLPAG_MONTO CHECK (MONTO IS NULL OR MONTO > 0);

    PRINT 'CK_RO_T_CF_LLPAG_MONTO: vacío o mayor que 0.';
END
GO

/* ============================================================================
   2. POR OPERADOR: % de comisiones y % de FPC
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'U') IS NULL
    PRINT 'Falta dbo.RO_T_CASHFLOW_LLAVES_OPERADOR (script 66): se saltea el % de comisiones y el de FPC.';
ELSE
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'PCT_COMISIONES') IS NULL
    BEGIN
        ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_OPERADOR ADD PCT_COMISIONES DECIMAL(7,2) NULL;
        PRINT 'Agregada RO_T_CASHFLOW_LLAVES_OPERADOR.PCT_COMISIONES (vacía = sin dato).';
    END
    ELSE
        PRINT 'RO_T_CASHFLOW_LLAVES_OPERADOR.PCT_COMISIONES ya existe: no se toca.';

    IF COL_LENGTH('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'PCT_FPC') IS NULL
    BEGIN
        ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_OPERADOR ADD PCT_FPC DECIMAL(7,2) NULL;
        PRINT 'Agregada RO_T_CASHFLOW_LLAVES_OPERADOR.PCT_FPC (vacía = sin dato).';
    END
    ELSE
        PRINT 'RO_T_CASHFLOW_LLAVES_OPERADOR.PCT_FPC ya existe: no se toca.';
END
GO

/* En otro lote: el CHECK nombra columnas que el lote de arriba recien creo. */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'PCT_COMISIONES') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'PCT_FPC') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_LLOPE_PCT_SEPARADOS'
                   AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR'))
BEGIN
    EXEC ('ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_OPERADOR ADD CONSTRAINT CK_RO_T_CF_LLOPE_PCT_SEPARADOS
           CHECK ((PCT_COMISIONES IS NULL OR PCT_COMISIONES >= 0) AND (PCT_FPC IS NULL OR PCT_FPC >= 0))');

    PRINT 'CK_RO_T_CF_LLOPE_PCT_SEPARADOS: los dos % vacíos o mayores o iguales a 0.';
END
GO

/* ============================================================================
   3. FACTURAS: los conceptos COMISIONES y FPC
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA', 'U') IS NULL
    PRINT 'Falta dbo.RO_T_CASHFLOW_LLAVES_FACTURA (script 68): se saltean los conceptos de facturas.';
ELSE IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_LLFAC_CONCEPTO'
                AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA')
                AND definition LIKE '%COMISIONES%' AND definition LIKE '%FPC%')
    PRINT 'CK_RO_T_CF_LLFAC_CONCEPTO ya admite COMISIONES y FPC: no se toca.';
ELSE
BEGIN
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_LLFAC_CONCEPTO'
               AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA'))
        ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_FACTURA DROP CONSTRAINT CK_RO_T_CF_LLFAC_CONCEPTO;

    ALTER TABLE dbo.RO_T_CASHFLOW_LLAVES_FACTURA ADD CONSTRAINT CK_RO_T_CF_LLFAC_CONCEPTO
        CHECK (CONCEPTO IN ('LLAVE', 'OTROS', 'COMISIONES', 'FPC', 'OBRA'));

    COMMIT TRANSACTION;
    PRINT 'CK_RO_T_CF_LLFAC_CONCEPTO admite LLAVE, OTROS, COMISIONES, FPC y OBRA.';
END
GO

/* ============================================================================
   LO QUE QUEDA POR HACER A MANO (solo lectura)
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_FACTURA', 'U') IS NOT NULL
BEGIN
    DECLARE @otros INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_LLAVES_FACTURA WHERE VIGENTE = 1 AND CONCEPTO = 'OTROS');

    PRINT 'Facturas marcadas vigentes con el concepto viejo «Otros gastos»: ' + CAST(@otros AS VARCHAR(10))
        + CASE WHEN @otros > 0 THEN '. Siguen reemplazando comisiones, FPC y escribano; deshacelas en Alquileres › Facturas '
               + 'y volvé a marcarlas como Comisiones o FPC.' ELSE '.' END;

    SELECT ID, COD_PROVEE, T_COMP, N_COMP, NRO_SUCURS, MES, USUARIO_ALTA, FECHA_ALTA
    FROM dbo.RO_T_CASHFLOW_LLAVES_FACTURA
    WHERE VIGENTE = 1 AND CONCEPTO = 'OTROS'
    ORDER BY NRO_SUCURS, MES;
END
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'PCT_OTROS') IS NOT NULL
BEGIN
    PRINT 'Operadores con % de otros gastos cargado (ya no se usa: cargá el % de comisiones y el de FPC):';

    SELECT COD_PROVEE, PCT_OTROS, USUARIO_ALTA, FECHA_ALTA
    FROM dbo.RO_T_CASHFLOW_LLAVES_OPERADOR
    WHERE VIGENTE = 1 AND PCT_OTROS IS NOT NULL
    ORDER BY COD_PROVEE;
END
GO

PRINT 'Ajustes de Llaves y Renovaciones listos.';
GO
