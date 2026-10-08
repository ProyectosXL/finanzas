/* ============================================================================
   CASHFLOW - SALDOS: BORRA las cuentas bancarias manuales que pasan a
   Interbanking, con su historico
   ============================================================================
   Base    : central. Se corre UNA VEZ, pero es reejecutable.
   Orden   : PRIMERO de feature/saldos-interbanking: antes de
             sql/cashflow_saldos_interbanking.sql y antes de publicar el codigo.
             Ver README-saldos.md, "Ejecucion de los scripts".
   Modo    : arranca en SIMULACION (@SIMULAR = 1): informa y no toca nada.
             Para borrar, cambiar a @SIMULAR = 0 y volver a correrlo.
   ----------------------------------------------------------------------------
   POR QUE EXISTE
   --------------
   Los saldos bancarios pasan a leerse en vivo de BI_T_SALDOS_INTERBANKING
   (Class/SaldosInterbanking.php). Las cuentas TIPO 'BANCO' que se cargaban a
   mano en RO_T_CASHFLOW_SALDOS_CUENTA, una por banco y moneda, quedan
   duplicando a Interbanking: si siguieran, el disponible sumaria dos veces el
   mismo banco. Este script las borra, con todo su historico de
   RO_T_CASHFLOW_SALDOS_DETALLE.

   ES LA UNICA EXCEPCION A "NO HAY BAJAS FISICAS" DEL MODULO, autorizada para
   esta tarea. Inhabilitarlas no alcanzaba: el historico seguiria atribuyendo a
   una carga manual saldos que de ahora en mas trae Interbanking, y el
   catalogo tendria dos cuentas para el mismo banco. Por eso deja constancia
   de lo que borro (ver 5).

   QUE SE CONSERVA: @CONSERVAR
   ---------------------------
   El catalogo no tiene NRO_BANCO, asi que no hay forma segura de saber que
   banco es cada cuenta y si viene por Interbanking. En vez de adivinar, la
   lista de lo que SE CONSERVA es explicita: @CONSERVAR, al principio, con
   nombres o IDs de cuentas. Por defecto, 'BTG Uy': no esta en Interbanking y
   sigue con carga manual. Todas las demas cuentas TIPO 'BANCO' se borran.
   Un banco nuevo sin Interbanking dado de alta antes de correr esto tiene que
   agregarse a la lista.

   QUE NO TOCA
   -----------
     - Ninguna cuenta de otro TIPO: el filtro es TIPO = 'BANCO' y nada mas.
     - Las cuentas de fondo, aunque alguna fuera TIPO 'BANCO': se conservan.
     - Las cabeceras de carga (RO_T_CASHFLOW_SALDOS_CARGA). Siguen teniendo las
       filas de las otras cuentas. Si alguna quedara sin ninguna fila, se
       informa y no se borra: es el registro de que esa carga existio.

   ANTES DE BORRAR, BUSCA REFERENCIAS
   ----------------------------------
   Aplicaciones de cobertura con ORIGEN = 'CTA_<id>' y cualquier FK que apunte
   al catalogo (entre ellas, los movimientos de fondos), salvo la del detalle,
   que es justamente lo que se borra. Si hay alguna, ABORTA en los dos modos y
   lo informa: borrar dejaria filas apuntando a una cuenta que no existe.

   TODO EN UNA TRANSACCION: primero el detalle, despues las cuentas, despues
   la constancia. Una falla a mitad de camino no deja nada a medias.

   ES REEJECUTABLE: una segunda corrida no encuentra nada que borrar y lo dice.

   LA CONSTANCIA PROTEGE EL DESPLIEGUE (5)
   ---------------------------------------
   El codigo no distingue una cuenta manual vieja de un banco que ya esta en
   Interbanking. Por eso SOLO LEE INTERBANKING SI EXISTE UNA FILA EN
   RO_T_CASHFLOW_SALDOS_DEPURACION: mientras no se corra este script en modo
   real, la pestana y el tablero siguen como antes, con las cuentas manuales,
   y un aviso critico dice que script falta. Asi, publicar el codigo antes que
   el script nunca suma un banco dos veces. La tabla la crea este script, solo
   en modo real, y guarda que se borro, que se conservo, quien y cuando.
   ============================================================================ */

SET NOCOUNT ON;
SET XACT_ABORT ON;

/* ---- Lo que se edita ------------------------------------------------------ */

DECLARE @SIMULAR BIT = 1;      -- 1 = solo informa. 0 = borra.

DECLARE @CONSERVAR TABLE (VALOR VARCHAR(80) COLLATE DATABASE_DEFAULT NOT NULL PRIMARY KEY);
INSERT INTO @CONSERVAR (VALOR) VALUES
    ('BTG Uy');                -- nombre o ID de cada cuenta a conservar

/* -------------------------------------------------------------------------- */

PRINT CASE WHEN @SIMULAR = 1
    THEN '=== SIMULACION: no se borra nada. Para borrar, @SIMULAR = 0. ==='
    ELSE '=== MODO REAL: se borra. ===' END;

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'U') IS NULL
   OR OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_DETALLE', 'U') IS NULL
BEGIN
    RAISERROR('No existen las tablas del modulo Saldos: corre antes sql/cashflow_saldos.sql.', 16, 1);
    RETURN;
END

/* ----------------------------------------------------------------------------
   1. Que se borra y que se conserva
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('tempdb..#BORRAR') IS NOT NULL DROP TABLE #BORRAR;
IF OBJECT_ID('tempdb..#CONSERVA') IS NOT NULL DROP TABLE #CONSERVA;
IF OBJECT_ID('tempdb..#REFERENCIAS') IS NOT NULL DROP TABLE #REFERENCIAS;

CREATE TABLE #BORRAR (
    ID INT NOT NULL PRIMARY KEY, NOMBRE VARCHAR(80) COLLATE DATABASE_DEFAULT,
    MONEDA CHAR(3) COLLATE DATABASE_DEFAULT,
    ACTIVO BIT, FILAS INT NOT NULL DEFAULT 0, FECHA_ULTIMO DATE NULL, ULTIMO_SALDO DECIMAL(19,4) NULL
);
CREATE TABLE #CONSERVA (
    ID INT NOT NULL PRIMARY KEY, NOMBRE VARCHAR(80) COLLATE DATABASE_DEFAULT,
    MONEDA CHAR(3) COLLATE DATABASE_DEFAULT,
    MOTIVO VARCHAR(100) COLLATE DATABASE_DEFAULT
);
CREATE TABLE #REFERENCIAS (TABLA SYSNAME, COLUMNA SYSNAME, FILAS INT);

INSERT INTO #BORRAR (ID, NOMBRE, MONEDA, ACTIVO)
SELECT C.ID, C.NOMBRE, C.MONEDA, C.ACTIVO
FROM dbo.RO_T_CASHFLOW_SALDOS_CUENTA C
WHERE C.TIPO = 'BANCO'
  AND NOT EXISTS (SELECT 1 FROM @CONSERVAR K
                  WHERE K.VALOR = C.NOMBRE OR K.VALOR = CAST(C.ID AS VARCHAR(12)));

INSERT INTO #CONSERVA (ID, NOMBRE, MONEDA, MOTIVO)
SELECT C.ID, C.NOMBRE, C.MONEDA, 'esta en @CONSERVAR'
FROM dbo.RO_T_CASHFLOW_SALDOS_CUENTA C
WHERE C.TIPO = 'BANCO'
  AND EXISTS (SELECT 1 FROM @CONSERVAR K
              WHERE K.VALOR = C.NOMBRE OR K.VALOR = CAST(C.ID AS VARCHAR(12)));

/* Un fondo no es un banco a la vista aunque tenga TIPO 'BANCO': se conserva.
   CLASE existe desde sql/cashflow_saldos_cuentas_fondo.sql; sin ella no hay
   fondos. Va por EXEC porque la columna puede no existir. */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'CLASE') IS NOT NULL
    EXEC(N'
        INSERT INTO #CONSERVA (ID, NOMBRE, MONEDA, MOTIVO)
        SELECT B.ID, B.NOMBRE, B.MONEDA, ''es una cuenta de fondo''
        FROM #BORRAR B
        INNER JOIN dbo.RO_T_CASHFLOW_SALDOS_CUENTA C ON C.ID = B.ID
        WHERE C.CLASE IN (''INVERSION'', ''COMITENTE'');

        DELETE B FROM #BORRAR B WHERE EXISTS (SELECT 1 FROM #CONSERVA K WHERE K.ID = B.ID);');

/* Cuantas filas de historico tiene cada una y cual era su ultimo saldo */
UPDATE B SET
    FILAS = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_SALDOS_DETALLE D WHERE D.ID_CUENTA = B.ID),
    FECHA_ULTIMO = U.FECHA_SALDO,
    ULTIMO_SALDO = U.COUNTABLE_BALANCE
FROM #BORRAR B
OUTER APPLY (SELECT TOP 1 D.FECHA_SALDO, D.COUNTABLE_BALANCE
             FROM dbo.RO_T_CASHFLOW_SALDOS_DETALLE D
             WHERE D.ID_CUENTA = B.ID
             ORDER BY D.FECHA_SALDO DESC, D.ID DESC) U;

/* PRINT no acepta subconsultas: los conteos van en variables */
DECLARE @nBorrar INT = (SELECT COUNT(*) FROM #BORRAR);
DECLARE @nFilas INT = (SELECT ISNULL(SUM(FILAS), 0) FROM #BORRAR);
DECLARE @nConserva INT = (SELECT COUNT(*) FROM #CONSERVA);

PRINT '';
PRINT 'SE BORRAN (primer resultado): ' + CAST(@nBorrar AS VARCHAR(10))
    + ' cuenta(s) con ' + CAST(@nFilas AS VARCHAR(10)) + ' fila(s) de historico.';
SELECT 'SE BORRA' AS ACCION, ID, NOMBRE, MONEDA, ACTIVO, FILAS AS FILAS_DETALLE,
       FECHA_ULTIMO, ULTIMO_SALDO
FROM #BORRAR ORDER BY NOMBRE, MONEDA;

PRINT 'SE CONSERVAN (segundo resultado): ' + CAST(@nConserva AS VARCHAR(10)) + ' cuenta(s).';
SELECT 'SE CONSERVA' AS ACCION, ID, NOMBRE, MONEDA, MOTIVO FROM #CONSERVA ORDER BY NOMBRE, MONEDA;

/* Lo que @CONSERVAR nombra y no existe: hay que darlo de alta a mano en
   Parametros -> Saldos -> Bancos sin Interbanking. */
IF EXISTS (SELECT 1 FROM @CONSERVAR K
           WHERE NOT EXISTS (SELECT 1 FROM #CONSERVA C
                             WHERE K.VALOR = C.NOMBRE OR K.VALOR = CAST(C.ID AS VARCHAR(12))))
BEGIN
    PRINT 'ATENCION: estos valores de @CONSERVAR no son ninguna cuenta TIPO BANCO. Si es un '
        + 'banco sin Interbanking, darlo de alta desde Parametros -> Saldos:';
    SELECT 'NO EXISTE' AS ACCION, K.VALOR FROM @CONSERVAR K
    WHERE NOT EXISTS (SELECT 1 FROM #CONSERVA C
                      WHERE K.VALOR = C.NOMBRE OR K.VALOR = CAST(C.ID AS VARCHAR(12)));
END

/* Las cabeceras de carga que quedarian sin ninguna fila. No se borran. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_SALDOS_DETALLE D
           WHERE D.ID_CUENTA IN (SELECT ID FROM #BORRAR)
             AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_SALDOS_DETALLE O
                             WHERE O.ID_CARGA = D.ID_CARGA
                               AND O.ID_CUENTA NOT IN (SELECT ID FROM #BORRAR)))
BEGIN
    PRINT 'ATENCION: estas cargas quedan sin ninguna fila. NO se borran: son el registro de '
        + 'que la carga existio.';
    SELECT DISTINCT 'CARGA SIN FILAS' AS ACCION, D.ID_CARGA
    FROM dbo.RO_T_CASHFLOW_SALDOS_DETALLE D
    WHERE D.ID_CUENTA IN (SELECT ID FROM #BORRAR)
      AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_SALDOS_DETALLE O
                      WHERE O.ID_CARGA = D.ID_CARGA
                        AND O.ID_CUENTA NOT IN (SELECT ID FROM #BORRAR));
END
ELSE
    PRINT 'Ninguna cabecera de carga queda sin filas.';

/* ----------------------------------------------------------------------------
   2. Referencias: si hay alguna, aborta
   ---------------------------------------------------------------------------- */
DECLARE @n INT, @sql NVARCHAR(MAX), @tabla SYSNAME, @col SYSNAME;

IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'U') IS NOT NULL
BEGIN
    EXEC sp_executesql
        N'SELECT @n = COUNT(*) FROM dbo.RO_T_CASHFLOW_COBERTURA_APLIC A
          WHERE A.ORIGEN IN (SELECT ''CTA_'' + CAST(B.ID AS VARCHAR(12)) FROM #BORRAR B)',
        N'@n INT OUTPUT', @n = @n OUTPUT;

    IF @n > 0
        INSERT INTO #REFERENCIAS VALUES ('RO_T_CASHFLOW_COBERTURA_APLIC', 'ORIGEN', @n);
END

/* Toda FK que apunte al catalogo, salvo la del detalle. Incluye los
   movimientos de fondos. */
DECLARE fks CURSOR LOCAL FAST_FORWARD FOR
    SELECT OBJECT_NAME(FKC.parent_object_id), COL_NAME(FKC.parent_object_id, FKC.parent_column_id)
    FROM sys.foreign_key_columns FKC
    WHERE FKC.referenced_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA')
      AND FKC.parent_object_id <> OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_DETALLE');

OPEN fks;
FETCH NEXT FROM fks INTO @tabla, @col;

WHILE @@FETCH_STATUS = 0
BEGIN
    SET @sql = N'SELECT @n = COUNT(*) FROM dbo.' + QUOTENAME(@tabla)
             + N' WHERE ' + QUOTENAME(@col) + N' IN (SELECT ID FROM #BORRAR)';
    EXEC sp_executesql @sql, N'@n INT OUTPUT', @n = @n OUTPUT;

    IF @n > 0
        INSERT INTO #REFERENCIAS VALUES (@tabla, @col, @n);

    FETCH NEXT FROM fks INTO @tabla, @col;
END

CLOSE fks;
DEALLOCATE fks;

IF EXISTS (SELECT 1 FROM #REFERENCIAS)
BEGIN
    SELECT 'REFERENCIA' AS ACCION, TABLA, COLUMNA, FILAS FROM #REFERENCIAS;
    RAISERROR('ABORTADO: hay filas que apuntan a cuentas que se iban a borrar (ver el resultado REFERENCIA). No se borro nada. Hay que resolverlas antes, o agregar esas cuentas a @CONSERVAR.', 16, 1);
    RETURN;
END

PRINT 'Ninguna otra tabla apunta a las cuentas que se borran.';

/* ----------------------------------------------------------------------------
   3. Simulacion: hasta aca
   ---------------------------------------------------------------------------- */
IF @SIMULAR = 1
BEGIN
    PRINT '';
    PRINT CASE WHEN @nBorrar > 0
        THEN 'SIMULACION: esto es lo que se borraria. No se toco nada.'
        ELSE 'SIMULACION: no hay cuentas bancarias manuales para borrar.' END;
    RETURN;
END

/* ----------------------------------------------------------------------------
   4. La bitacora, si falta. Solo en modo real: la simulacion no crea nada.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_DEPURACION', 'U') IS NULL
BEGIN
    EXEC(N'
        CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_DEPURACION (
            ID               INT IDENTITY(1,1) NOT NULL,
            CUENTAS_BORRADAS INT           NOT NULL,
            FILAS_BORRADAS   INT           NOT NULL,
            BORRADAS         VARCHAR(MAX)  NULL,
            CONSERVADAS      VARCHAR(MAX)  NULL,
            USUARIO_ALTA     VARCHAR(50)   NULL,
            FECHA_ALTA       DATETIME      NULL CONSTRAINT DF_CF_SAL_DEP_FALTA DEFAULT (GETDATE()),
            CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_DEPURACION PRIMARY KEY CLUSTERED (ID)
        );');

    PRINT 'Creada RO_T_CASHFLOW_SALDOS_DEPURACION.';
END

/* ----------------------------------------------------------------------------
   5. El borrado y la constancia, en una transaccion
   ---------------------------------------------------------------------------- */
DECLARE @filas INT = 0, @cuentas INT = 0;
DECLARE @borradas VARCHAR(MAX) =
    (SELECT STRING_AGG(CAST(CAST(ID AS VARCHAR(12)) + ' ' + NOMBRE + ' ' + MONEDA + ' ('
        + CAST(FILAS AS VARCHAR(10)) + ' filas)' AS VARCHAR(MAX)), '; ') FROM #BORRAR);
DECLARE @conservadas VARCHAR(MAX) =
    (SELECT STRING_AGG(CAST(CAST(ID AS VARCHAR(12)) + ' ' + NOMBRE + ' ' + MONEDA AS VARCHAR(MAX)), '; ')
     FROM #CONSERVA);

BEGIN TRANSACTION;

DELETE D
FROM dbo.RO_T_CASHFLOW_SALDOS_DETALLE D
WHERE D.ID_CUENTA IN (SELECT ID FROM #BORRAR);
SET @filas = @@ROWCOUNT;

DELETE C
FROM dbo.RO_T_CASHFLOW_SALDOS_CUENTA C
WHERE C.ID IN (SELECT ID FROM #BORRAR)
  AND C.TIPO = 'BANCO';
SET @cuentas = @@ROWCOUNT;

/* La constancia va siempre que la corrida es real, aunque no haya borrado
   nada: es lo que habilita la lectura de Interbanking en el codigo, tambien en
   un entorno que nunca tuvo bancos manuales. */
EXEC sp_executesql
    N'INSERT INTO dbo.RO_T_CASHFLOW_SALDOS_DEPURACION
          (CUENTAS_BORRADAS, FILAS_BORRADAS, BORRADAS, CONSERVADAS, USUARIO_ALTA)
      VALUES (@c, @f, @b, @k, LEFT(SUSER_SNAME(), 50))',
    N'@c INT, @f INT, @b VARCHAR(MAX), @k VARCHAR(MAX)',
    @c = @cuentas, @f = @filas, @b = @borradas, @k = @conservadas;

COMMIT TRANSACTION;

PRINT '';
PRINT CASE WHEN @cuentas = 0
    THEN 'No habia cuentas bancarias manuales para borrar. Constancia registrada.'
    ELSE 'Borradas ' + CAST(@cuentas AS VARCHAR(10)) + ' cuenta(s) y ' + CAST(@filas AS VARCHAR(10))
         + ' fila(s) de historico. Constancia en RO_T_CASHFLOW_SALDOS_DEPURACION.' END;
GO
