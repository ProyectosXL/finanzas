/* ============================================================================
   MODULO CASHFLOW - TARJETAS SOCIOS POR BANCO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_tarjetas.sql y de
            sql/cashflow_auditoria_usuario.sql (USUARIO_BAJA / FECHA_BAJA del
            maestro). Corta con un mensaje si falta cualquiera de los dos.
   ----------------------------------------------------------------------------
   QUE HACE ESTE SCRIPT

   Tarjetas Socios pasa a ser POR BANCO y se va el nombre del socio: un registro
   por banco con su % de cobertura y su dia de vencimiento, que recibe los
   resumenes del banco y estima con el promedio de sus ultimos 3.

   EL BANCO ES UNA FILA DEL MISMO MAESTRO, de tipo SOCIOS_BANCO y SIN USUARIO:
   asi reusa la tabla de resumenes con su FK, su historial, el formulario de
   carga y la regla de estimacion, sin una segunda tabla de resumenes.

     1. ID_USUARIO y NOMBRE_USUARIO pasan a admitir NULL. Las dos columnas estan
        en UX_RO_T_CF_TARJ_IDENTIDAD y en IX_RO_T_CF_TARJ_TIPO_ACTIVA, y SQL
        Server no deja cambiar una columna indexada: se bajan los dos indices, se
        cambian las columnas y se vuelven a crear IGUALES, en una transaccion.

     2. El CHECK del tipo admite SOCIOS_BANCO, y uno nuevo exige que una fila de
        ese tipo NO tenga usuario ni ultimos 4, y que una de cualquier otro tipo
        SI tenga usuario.

     3. UNO POR BANCO, sin indice nuevo: UX_RO_T_CF_TARJ_IDENTIDAD es
        (COD_BANCO, ID_USUARIO, ULTIMOS_4), y SQL Server trata los NULL como
        iguales en un UNIQUE, asi que dos filas del mismo banco sin usuario ni
        ultimos 4 chocan. Solo las de SOCIOS_BANCO tienen el usuario en NULL.

     4. Las tarjetas de tipo SOCIO se dan de BAJA LOGICA (ACTIVA = 0), con
        USUARIO_BAJA = 'MIGRACION', como sql/cashflow_comex_fecha_maestra.sql. Sus
        resumenes NO SE MIGRAN: quedan colgando de ellas como historial, y la base
        historica se vuelve a cargar por banco. Dejan de proyectar.

   NO DA DE ALTA LOS BANCOS: se cargan en Parametros -> Tarjetas, con su % y su
   dia. Hasta que un banco tenga su base historica no proyecta, y la pestana y el
   tablero avisan cuanto proyectaban sus tarjetas.

   ES REEJECUTABLE: cada paso pregunta antes (COLUMNPROPERTY, el texto del CHECK,
   sys.check_constraints) y la baja solo toca las SOCIO activas. No borra filas.
   ============================================================================ */

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
   OR COL_LENGTH('dbo.RO_T_CASHFLOW_TARJETAS', 'USUARIO_BAJA') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_TARJETAS o su USUARIO_BAJA. Corre primero sql/cashflow_tarjetas.sql y sql/cashflow_auditoria_usuario.sql. No se cambio nada.', 16, 1);
END
GO

/* ============================================================================
   1. ID_USUARIO Y NOMBRE_USUARIO ADMITEN NULL
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND COLUMNPROPERTY(OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS'), 'ID_USUARIO', 'AllowsNull') = 0
BEGIN
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_TARJ_IDENTIDAD'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS'))
        DROP INDEX UX_RO_T_CF_TARJ_IDENTIDAD ON dbo.RO_T_CASHFLOW_TARJETAS;

    IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RO_T_CF_TARJ_TIPO_ACTIVA'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS'))
        DROP INDEX IX_RO_T_CF_TARJ_TIPO_ACTIVA ON dbo.RO_T_CASHFLOW_TARJETAS;

    ALTER TABLE dbo.RO_T_CASHFLOW_TARJETAS ALTER COLUMN ID_USUARIO INT NULL;
    ALTER TABLE dbo.RO_T_CASHFLOW_TARJETAS
        ALTER COLUMN NOMBRE_USUARIO NVARCHAR(200) COLLATE Modern_Spanish_CI_AI NULL;

    /* Los mismos dos indices de sql/cashflow_tarjetas.sql, sin cambios. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJ_IDENTIDAD
        ON dbo.RO_T_CASHFLOW_TARJETAS (COD_BANCO, ID_USUARIO, ULTIMOS_4);

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_TARJ_TIPO_ACTIVA
        ON dbo.RO_T_CASHFLOW_TARJETAS (TIPO, ACTIVA)
        INCLUDE (COD_BANCO, ID_USUARIO, NOMBRE_USUARIO, ULTIMOS_4,
                 PCT_COBERTURA, DIA_VENCIMIENTO);

    COMMIT TRANSACTION;

    PRINT 'ID_USUARIO y NOMBRE_USUARIO admiten NULL; los dos indices se recrearon iguales.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
    PRINT 'ID_USUARIO ya admite NULL: no se toca.';
GO

/* ============================================================================
   2. EL TIPO SOCIOS_BANCO, Y QUE NO TENGA USUARIO
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints
                   WHERE name = 'CK_RO_T_CF_TARJ_TIPO'
                     AND definition LIKE '%SOCIOS_BANCO%')
BEGIN
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_TARJ_TIPO')
        ALTER TABLE dbo.RO_T_CASHFLOW_TARJETAS DROP CONSTRAINT CK_RO_T_CF_TARJ_TIPO;

    /* SOCIO queda en la lista: las tarjetas viejas siguen en la tabla, de baja,
       como historial. Lo que ya no se puede es dar de alta una: lo rechaza el
       backend. */
    ALTER TABLE dbo.RO_T_CASHFLOW_TARJETAS ADD CONSTRAINT CK_RO_T_CF_TARJ_TIPO
        CHECK (TIPO IN ('CORPORATIVA', 'SUPERVISORA', 'SOCIO', 'SOCIOS_BANCO'));

    COMMIT TRANSACTION;

    PRINT 'CK_RO_T_CF_TARJ_TIPO admite SOCIOS_BANCO.';
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_TARJ_USUARIO_TIPO')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_TARJETAS ADD CONSTRAINT CK_RO_T_CF_TARJ_USUARIO_TIPO
        CHECK ((TIPO = 'SOCIOS_BANCO' AND ID_USUARIO IS NULL AND NOMBRE_USUARIO IS NULL
                AND ULTIMOS_4 IS NULL)
            OR (TIPO <> 'SOCIOS_BANCO' AND ID_USUARIO IS NOT NULL AND NOMBRE_USUARIO IS NOT NULL));

    PRINT 'Creado CK_RO_T_CF_TARJ_USUARIO_TIPO: un banco de socios no tiene usuario, una tarjeta si.';
END
GO

/* ============================================================================
   3. LAS TARJETAS DE SOCIO, DE BAJA
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
BEGIN
    UPDATE dbo.RO_T_CASHFLOW_TARJETAS
    SET ACTIVA = 0, USUARIO_BAJA = 'MIGRACION', FECHA_BAJA = GETDATE(),
        USUARIO_MODIF = 'MIGRACION', FECHA_MODIF = GETDATE()
    WHERE TIPO = 'SOCIO' AND ACTIVA = 1;

    PRINT 'Tarjetas de socio dadas de baja: ' + CAST(@@ROWCOUNT AS VARCHAR(10))
        + '. Sus resumenes quedan como historial y no se migran.';
END
GO

/* ============================================================================
   4. CONTROL: no inserta ni modifica nada.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
BEGIN
    SELECT TIPO, COD_BANCO, COUNT(*) AS FILAS,
           SUM(CASE WHEN ACTIVA = 1 THEN 1 ELSE 0 END) AS ACTIVAS
    FROM dbo.RO_T_CASHFLOW_TARJETAS
    WHERE TIPO IN ('SOCIO', 'SOCIOS_BANCO')
    GROUP BY TIPO, COD_BANCO
    ORDER BY TIPO, COD_BANCO;
END
GO
