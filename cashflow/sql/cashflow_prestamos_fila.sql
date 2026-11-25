/* ============================================================================
   MODULO CASHFLOW - LA SECCION "FINANCIAMIENTO" DEL TABLERO (script 72)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_estructura_grupos.sql (las columnas GRUPO,
            NATURALEZA y GRUPO_NOMBRE). Sin el de grupos, las filas se crean
            igual pero sueltas, y lo dice. Independiente de los scripts 70 y 71:
            sin el 70 las filas suman cero (y la pestaña avisa que falta).
   ----------------------------------------------------------------------------
   QUE CAMBIA

   1. EL CHECK DE NATURALEZA admite 'SIMULADO' (CK_RO_T_CASHFLOW_CONF_FILA_
      NATURALEZA), la parte de un concepto que sale de prestamos simulados. El
      tablero la muestra solo con el interruptor "Incluir simulaciones".

   2. UNA SECCION NUEVA, FINANCIAMIENTO, entre Egresos (ORDEN 80) y Resultados
      (100), con ORDEN 85 y sin padre:

        Desembolsos de prestamos   INGRESO  FINANCIERO / DESEMBOLSO_REAL|_PROY|_SIM
        Amortizacion de capital    EGRESO   FINANCIERO / CAPITAL_REAL|_PROY|_SIM
        Intereses y gastos         EGRESO   FINANCIERO / INTERESES_REAL|_PROY|_SIM
        Total Financiamiento       SUBTOTAL

      Cada concepto en TRES FILAS CONSECUTIVAS con el mismo GRUPO y su
      NATURALEZA (REAL, PROYECTADO, SIMULADO): el tablero las dibuja en un
      renglon que se abre.

   POR QUE ENTRA SOLA AL FLUJO NETO. El alcance de las filas calculadas es
   POSICIONAL: Flujo Neto (sin cobertura), en Resultados, suma todo lo que tiene
   por encima, y la cobertura automatica y el Saldo Final, todo lo de arriba.
   Con ORDEN 85 la seccion queda arriba de los dos, sin tocar el motor.

   LA FILA "FINANCIERO" DE AJUSTES (FINANCIERO / MOVIMIENTOS, inactiva, como
   toda la seccion Ajustes) NO SE TOCA: queda inactiva. El registro reusa la
   entrada FINANCIERO para Prestamos y deja MOVIMIENTOS declarada en desuso,
   en cero: el validador revisa tambien las filas inactivas, y sin la serie
   Parametros -> Cashflow no dejaria guardar.

   EL DIA QUE SE CORRE el tablero SE MUEVE POR LOS PRESTAMOS VIGENTES: sin
   ninguno cargado, la seccion suma cero.

   ES REEJECUTABLE: la seccion y las filas se crean con NOT EXISTS sobre el
   CODIGO, el CHECK se rehace solo si todavia no admite SIMULADO, y no se pisa
   nada de lo que alguien haya editado desde Parametros -> Cashflow.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guardas duras.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NULL OR OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_SECCION', 'U') IS NULL
BEGIN
    RAISERROR('Faltan las tablas de estructura. Corre primero sql/cashflow_estructura.sql.', 16, 1);
END
GO

IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE ORDEN = 85 AND CODIGO <> 'FINANCIAMIENTO')
BEGIN
    RAISERROR('Ya hay otra seccion con ORDEN 85. Revisa el orden entre Egresos y Resultados antes de correr este script.', 16, 1);
END
GO

/* ============================================================================
   1. EL CHECK DE NATURALEZA, CON SIMULADO
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'NATURALEZA') IS NOT NULL
BEGIN
    DECLARE @def NVARCHAR(MAX) = (SELECT definition FROM sys.check_constraints
                                  WHERE name = 'CK_RO_T_CASHFLOW_CONF_FILA_NATURALEZA'
                                    AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA'));

    IF @def IS NULL OR @def NOT LIKE '%SIMULADO%'
    BEGIN
        IF @def IS NOT NULL
            ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA DROP CONSTRAINT CK_RO_T_CASHFLOW_CONF_FILA_NATURALEZA;

        EXEC('ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA WITH CHECK ADD CONSTRAINT CK_RO_T_CASHFLOW_CONF_FILA_NATURALEZA
              CHECK (NATURALEZA IS NULL OR NATURALEZA IN (''REAL'', ''PROYECTADO'', ''SIMULADO''))');

        PRINT 'CK_RO_T_CASHFLOW_CONF_FILA_NATURALEZA: admite REAL, PROYECTADO y SIMULADO.';
    END
    ELSE
        PRINT 'El CHECK de NATURALEZA ya admite SIMULADO: no se toca.';
END
ELSE
    PRINT 'AVISO: falta la columna NATURALEZA (sql/cashflow_estructura_grupos.sql). Las filas quedan sueltas.';
GO

/* ============================================================================
   2. LA SECCION Y LAS FILAS, EN UNA TRANSACCION
   ============================================================================ */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'FINANCIAMIENTO')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_SECCION (CODIGO, NOMBRE, ROL, ID_PADRE, ORDEN, ACTIVO)
    VALUES ('FINANCIAMIENTO', 'Financiamiento', 'MOVIMIENTO', NULL, 85, 1);

    PRINT 'Creada la seccion FINANCIAMIENTO (orden 85, entre Egresos y Resultados).';
END
ELSE
    PRINT 'La seccion FINANCIAMIENTO ya existe: no se toca.';

DECLARE @F TABLE (CODIGO VARCHAR(50), NOMBRE VARCHAR(100), TIPO VARCHAR(20), COMPUTA BIT, SERIE VARCHAR(50), ORDEN INT);

INSERT INTO @F VALUES
    ('FIN_DESEMBOLSO_REAL', 'Desembolsos de préstamos',               'INGRESO',  1, 'DESEMBOLSO_REAL', 10),
    ('FIN_DESEMBOLSO_PROY', 'Desembolsos de préstamos proyectados',   'INGRESO',  1, 'DESEMBOLSO_PROY', 11),
    ('FIN_DESEMBOLSO_SIM',  'Desembolsos de préstamos simulados',     'INGRESO',  1, 'DESEMBOLSO_SIM',  12),
    ('FIN_CAPITAL_REAL',    'Amortización de capital',                'EGRESO',   1, 'CAPITAL_REAL',    20),
    ('FIN_CAPITAL_PROY',    'Amortización de capital proyectada',     'EGRESO',   1, 'CAPITAL_PROY',    21),
    ('FIN_CAPITAL_SIM',     'Amortización de capital simulada',       'EGRESO',   1, 'CAPITAL_SIM',     22),
    ('FIN_INTERESES_REAL',  'Intereses y gastos',                     'EGRESO',   1, 'INTERESES_REAL',  30),
    ('FIN_INTERESES_PROY',  'Intereses y gastos proyectados',         'EGRESO',   1, 'INTERESES_PROY',  31),
    ('FIN_INTERESES_SIM',   'Intereses y gastos simulados',           'EGRESO',   1, 'INTERESES_SIM',   32),
    ('SUB_FINANCIAMIENTO',  'Total Financiamiento',                   'SUBTOTAL', 0, NULL,              40);

INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
    (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
SELECT F.CODIGO, F.NOMBRE, 'FINANCIAMIENTO', F.TIPO, F.COMPUTA,
       CASE WHEN F.SERIE IS NULL THEN NULL ELSE 'FINANCIERO' END, F.SERIE, F.ORDEN, 1
FROM @F F
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA X WHERE X.CODIGO = F.CODIGO);

PRINT CONCAT('Filas de Financiamiento creadas: ', @@ROWCOUNT, ' de 10 (las que ya existian no se tocan).');

COMMIT TRANSACTION;
GO

/* ============================================================================
   3. LOS TRES GRUPOS. Solo si ya se corrio sql/cashflow_estructura_grupos.sql.
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NULL
BEGIN
    PRINT 'AVISO: faltan las columnas de grupos (sql/cashflow_estructura_grupos.sql). Las nueve filas '
        + 'quedan sueltas: suman bien, pero no se dibujan en renglones que se abren.';
END
ELSE
BEGIN
    EXEC('
    DECLARE @G TABLE (CODIGO VARCHAR(50), GRUPO VARCHAR(50), NATURALEZA VARCHAR(20), NOMBRE VARCHAR(100));

    INSERT INTO @G VALUES
        (''FIN_DESEMBOLSO_REAL'', ''FIN_DESEMBOLSO'', ''REAL'',       ''Desembolsos de préstamos''),
        (''FIN_DESEMBOLSO_PROY'', ''FIN_DESEMBOLSO'', ''PROYECTADO'', NULL),
        (''FIN_DESEMBOLSO_SIM'',  ''FIN_DESEMBOLSO'', ''SIMULADO'',   NULL),
        (''FIN_CAPITAL_REAL'',    ''FIN_CAPITAL'',    ''REAL'',       ''Amortización de capital''),
        (''FIN_CAPITAL_PROY'',    ''FIN_CAPITAL'',    ''PROYECTADO'', NULL),
        (''FIN_CAPITAL_SIM'',     ''FIN_CAPITAL'',    ''SIMULADO'',   NULL),
        (''FIN_INTERESES_REAL'',  ''FIN_INTERESES'',  ''REAL'',       ''Intereses y gastos''),
        (''FIN_INTERESES_PROY'',  ''FIN_INTERESES'',  ''PROYECTADO'', NULL),
        (''FIN_INTERESES_SIM'',   ''FIN_INTERESES'',  ''SIMULADO'',   NULL);

    UPDATE F
    SET GRUPO = G.GRUPO, NATURALEZA = G.NATURALEZA, GRUPO_NOMBRE = G.NOMBRE
    FROM dbo.RO_T_CASHFLOW_CONF_FILA F
    JOIN @G G ON G.CODIGO = F.CODIGO
    WHERE ISNULL(F.GRUPO, '''') <> G.GRUPO OR ISNULL(F.NATURALEZA, '''') <> G.NATURALEZA
       OR ISNULL(F.GRUPO_NOMBRE, '''') <> ISNULL(G.NOMBRE, '''');
    ');

    PRINT 'Financiamiento: las nueve filas quedan en tres renglones que se abren (Real, Proyectado, Simulado).';
END
GO

/* ============================================================================
   4. CONTROLES: COMO QUEDO
   ============================================================================ */
PRINT 'Secciones, en orden:';
GO

SELECT ORDEN, CODIGO, NOMBRE, ROL, ID_PADRE, ACTIVO FROM dbo.RO_T_CASHFLOW_CONF_SECCION ORDER BY ORDEN;
GO

PRINT 'Financiamiento, como queda:';
GO

SELECT ORDEN, CODIGO, NOMBRE, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ACTIVO
FROM dbo.RO_T_CASHFLOW_CONF_FILA
WHERE SECCION = 'FINANCIAMIENTO'
ORDER BY ORDEN;
GO

/* 4a. DOBLE CONTEO: ninguna otra fila activa de FINANCIERO ademas de las nueve.
       Un total (DESEMBOLSO, CAPITAL, INTERESES) activo junto a sus partes
       contaria dos veces el mismo pago; el validador lo rechaza igual. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'FINANCIERO' AND ACTIVO = 1
             AND CODIGO NOT IN ('FIN_DESEMBOLSO_REAL', 'FIN_DESEMBOLSO_PROY', 'FIN_DESEMBOLSO_SIM',
                                'FIN_CAPITAL_REAL', 'FIN_CAPITAL_PROY', 'FIN_CAPITAL_SIM',
                                'FIN_INTERESES_REAL', 'FIN_INTERESES_PROY', 'FIN_INTERESES_SIM'))
BEGIN
    PRINT 'ATENCION: DOBLE CONTEO. Hay otra fila activa leyendo FINANCIERO: el mismo pago se contaria dos veces. '
        + 'Inhabilitala. Se lista abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE, ORDEN, ACTIVO FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'FINANCIERO' AND ACTIVO = 1
      AND CODIGO NOT IN ('FIN_DESEMBOLSO_REAL', 'FIN_DESEMBOLSO_PROY', 'FIN_DESEMBOLSO_SIM',
                         'FIN_CAPITAL_REAL', 'FIN_CAPITAL_PROY', 'FIN_CAPITAL_SIM',
                         'FIN_INTERESES_REAL', 'FIN_INTERESES_PROY', 'FIN_INTERESES_SIM');
END
ELSE
    PRINT 'FINANCIERO alimenta solo las nueve filas de Financiamiento: sin doble conteo.';
GO

/* 4b. La fila vieja de Ajustes sigue inactiva. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'FINANCIERO' AND ORIGEN_SERIE = 'MOVIMIENTOS' AND ACTIVO = 1)
    PRINT 'ATENCION: la fila FINANCIERO / MOVIMIENTOS esta ACTIVA. Esa serie esta en desuso y vale cero: inhabilitala desde Parametros -> Cashflow.';
ELSE
    PRINT 'La fila FINANCIERO / MOVIMIENTOS (Ajustes) sigue inactiva.';
GO

/* 4c. La seccion queda entre Egresos y Resultados. */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION S
               WHERE S.CODIGO = 'FINANCIAMIENTO'
                 AND S.ORDEN > (SELECT ORDEN FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'EGRESOS')
                 AND S.ORDEN < (SELECT ORDEN FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'RESULTADOS'))
    PRINT 'ATENCION: Financiamiento NO queda entre Egresos y Resultados: el Flujo Neto (sin cobertura) podria no tomarla. Revisa el orden.';
ELSE
    PRINT 'Financiamiento queda entre Egresos y Resultados: entra en el Flujo Neto (sin cobertura) por su posicion.';
GO

PRINT 'Seccion Financiamiento lista.';
GO
