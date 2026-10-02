/* ============================================================================
   CASHFLOW - AUDITORIA DE USUARIO EN TODAS LAS TABLAS QUE ESCRIBE EL MODULO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ANTES de publicar el codigo de feature/cashflow-auditoria-usuario.
            Sin estas columnas las escrituras del codigo nuevo FALLAN: no hay
            vuelta atras a las columnas viejas. Independiente de
            sql/cashflow_permisos_edicion.sql, que va a apps.
   ----------------------------------------------------------------------------
   QUE HACE

   Lleva a todas las tablas el esquema de Tarjetas (sql/cashflow_tarjetas.sql):

       USUARIO_ALTA  / FECHA_ALTA    quien y cuando creo la fila
       USUARIO_MODIF / FECHA_MODIF   quien y cuando la toco por ultima vez
       USUARIO_BAJA  / FECHA_BAJA    quien y cuando la dio de baja, solo donde
                                     hay baja logica (VIGENTE / ACTIVO / ACTIVA)

   El backend escribe SIEMPRE esas columnas y el front no manda ninguna. Las
   fechas las pone el servidor (GETDATE()). Ver "Auditoria y permisos de
   escritura" en README-cashflow.md.

   1. AGREGA LAS COLUMNAS QUE FALTEN, todas NULL.
      FECHA_ALTA y FECHA_MODIF llevan DEFAULT (GETDATE()) SIN WITH VALUES: las
      filas viejas quedan en NULL -nadie sabe cuando se crearon- y las nuevas
      toman la fecha. Una FECHA_ALTA o FECHA_BAJA que ya existe se reusa tal
      como esta.

   2. COPIA LO QUE YA HAY, SIN INVENTAR, y solo donde la columna nueva esta en
      NULL (asi una segunda corrida no pisa nada). Depende de como usa hoy la
      tabla su columna USUARIO:

        MODIF  tablas de "ultima edicion": USUARIO se pisa en cada cambio, asi
               que dice quien la toco por ultima vez.
                 USUARIO -> USUARIO_MODIF, FECHA_UPDATE / FECHA_MOD -> FECHA_MODIF
        ALTA   tablas de "una fila por carga": cada cambio es una fila nueva y
               USUARIO dice quien la cargo.
                 USUARIO -> USUARIO_ALTA

      USUARIO_BAJA no se copia de ningun lado: ninguna tabla guardaba quien dio
      de baja. Las bajas historicas quedan con FECHA_BAJA y sin usuario.

   3. LAS COLUMNAS VIEJAS NO SE BORRAN. USUARIO, FECHA_UPDATE y FECHA_MOD
      quedan EN DESUSO: el codigo deja de leerlas y de escribirlas. Su DROP es
      un script aparte, posterior al pase a produccion. Todas son NULL o tienen
      DEFAULT, asi que no bloquean ningun INSERT que ya no las nombre
      (verificado contra la base el 02/10/2026).

   CASOS PARTICULARES
     - RO_T_PARAMETROS_DESC_CLIENTES NO ES DE ESTE MODULO: la escribe tambien
       Tesoreria (administracion/tesoreria/cobranzas/api/parametros_controller.php)
       y la leen otras tres pantallas de alla. Se agregan columnas NULL y SIN
       DEFAULTS, para no cambiar nada de lo que hace el otro sistema. Su
       FECHA_MOD tampoco queda en desuso: el cashflow la sigue escribiendo
       porque Tesoreria la usa.
     - RO_T_CASHFLOW_COMEX_CRONO_NAC no tiene script de creacion en el repo: se
       toca solo si existe.
     - Las tablas que llenan los procesos (historia y presupuesto de Comex,
       historico de ventas) llevan solo USUARIO_ALTA / FECHA_ALTA: se reescriben
       enteras en cada corrida, no hay modificacion ni baja que registrar.
     - RO_T_CASHFLOW_TARJETAS ya tenia ALTA y MODIF; recibe USUARIO_BAJA /
       FECHA_BAJA por su ACTIVA. Las otras tres de Tarjetas ya cumplen y no
       estan en la lista.

   NO ESTAN EN LA LISTA, A PROPOSITO: COBRANZAS_PARAM_DESC, COBRANZAS_CLIENTE_CONFIG
   y VENTAS_PRECHEQ (el codigo no las escribe) ni DOLARES_COMITENTE y
   SALDO_INVERSIONES (las escribia Otros Ingresos, cuyas pestañas se eliminaron).

   Reejecutable: cada columna va con COL_LENGTH y cada copia con IS NULL. Si una
   tabla no existe, avisa y sigue con las demas. No borra nada.
   ========================================================================== */

SET NOCOUNT ON;
GO

/* ---- La lista, una fila por tabla ----
     BAJA   1 = tiene baja logica: lleva USUARIO_BAJA / FECHA_BAJA
     COPIA  MODIF, ALTA o NULL (ver arriba)
     FECHA  columna vieja de la que se copia la fecha, o NULL
     A      a que columna nueva va esa fecha (FECHA_MODIF o FECHA_ALTA)
     SOLO_ALTA   1 = procesos: no lleva MODIF ni BAJA
     SIN_DEFAULT 1 = tabla compartida: ninguna columna nueva lleva DEFAULT */
IF OBJECT_ID('tempdb..#AUDITORIA') IS NOT NULL
    DROP TABLE #AUDITORIA;

CREATE TABLE #AUDITORIA (
    ORDEN       INT IDENTITY(1,1),
    TABLA       SYSNAME     NOT NULL,
    BAJA        BIT         NOT NULL,
    COPIA       VARCHAR(5)  NULL,
    FECHA       SYSNAME     NULL,
    A           SYSNAME     NULL,
    SOLO_ALTA   BIT         NOT NULL DEFAULT (0),
    SIN_DEFAULT BIT         NOT NULL DEFAULT (0)
);

INSERT INTO #AUDITORIA (TABLA, BAJA, COPIA, FECHA, A) VALUES
    /* Cobranzas */
    ('RO_T_CASHFLOW_COBEL_PROCESADORA',        1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_COBEL_ALICUOTA',           1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_COBEL_MOVIMIENTO',         1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC',    1, 'MODIF', 'FECHA_MOD',    'FECHA_MODIF'),
    ('RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL',0, 'MODIF', 'FECHA_MOD',    'FECHA_MODIF'),
    ('RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO',      0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    /* Cobertura y fondos */
    ('RO_T_CASHFLOW_COBERTURA_APLIC',          1, 'ALTA',  NULL,           NULL),
    ('RO_T_CASHFLOW_SALDOS_FONDO_MOV',         1, 'ALTA',  NULL,           NULL),
    /* Comex y compras */
    ('RO_T_CASHFLOW_COMEX_FECHA_EDIT',         1, 'ALTA',  NULL,           NULL),
    ('RO_T_CASHFLOW_COMEX_PAGADO',             1, 'ALTA',  NULL,           NULL),
    ('RO_T_CASHFLOW_COMEX_CRONO_NAC',          0, NULL,    'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE',      1, 'ALTA',  NULL,           NULL),
    /* JOB_LOG: una fila por corrida; el SP la actualiza al terminar */
    ('RO_T_CASHFLOW_JOB_LOG',                  0, 'ALTA',  NULL,           NULL),
    /* Estructura y parametros */
    ('RO_T_CASHFLOW_ECHEQ_EXCLUIDO',           1, 'ALTA',  NULL,           NULL),
    ('RO_T_CASHFLOW_CONF_SECCION',             1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_CONF_FILA',                1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_INFLACION_MES',            0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT',     1, 'ALTA',  NULL,           NULL),
    ('RO_T_CASHFLOW_PARAMETROS',               0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    /* Logistica y proveedores */
    ('RO_T_CASHFLOW_LOGISTICA_FLETEROS',       1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO',     1, 'ALTA',  NULL,           NULL),
    /* CATEG: una version por importacion; la fecha de la carga es FECHA_IMPORTACION */
    ('RO_T_CASHFLOW_PROV_LOCALES_CATEG',       1, 'ALTA',  'FECHA_IMPORTACION', 'FECHA_ALTA'),
    ('RO_T_CASHFLOW_PROV_LOCALES_PAGO',        0, 'MODIF', 'FECHA_MOD',    'FECHA_MODIF'),
    ('RO_T_CASHFLOW_PROV_LOCALES_OPCIONES',    1, NULL,    NULL,           NULL),
    /* Saldos */
    ('RO_T_CASHFLOW_SALDOS_CUENTA',            1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_SALDOS_CARGA',             1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_SALDOS_DETALLE',           0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_SALDOS_SUCURSAL',          1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_SALDOS_LOCAL',             0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL',      1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    /* Echeqs y ventas */
    ('RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE',    1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_ECHEQ_PRECHEQ',            0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_VENTAS_INDICE',            0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_VENTAS_PARTIC',            0, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    ('RO_T_CASHFLOW_VENTAS_MIX',               1, 'MODIF', 'FECHA_UPDATE', 'FECHA_MODIF'),
    /* Tarjetas: el maestro solo necesita la baja de su ACTIVA */
    ('RO_T_CASHFLOW_TARJETAS',                 1, NULL,    NULL,           NULL);

/* Tabla de otro sistema: columnas NULL y sin defaults */
INSERT INTO #AUDITORIA (TABLA, BAJA, COPIA, FECHA, A, SIN_DEFAULT) VALUES
    ('RO_T_PARAMETROS_DESC_CLIENTES',          0, NULL,    'FECHA_MOD',    'FECHA_MODIF', 1);

/* Las que llenan los procesos: solo alta, con el origen del proceso */
INSERT INTO #AUDITORIA (TABLA, BAJA, COPIA, FECHA, A, SOLO_ALTA) VALUES
    ('RO_T_CASHFLOW_COMEX_RECEP_HIST',         0, NULL, NULL, NULL, 1),
    ('RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN',     0, NULL, NULL, NULL, 1),
    ('RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE',   0, NULL, NULL, NULL, 1),
    ('RO_T_CASHFLOW_VENTAS_HIST',              0, NULL, NULL, NULL, 1),
    ('RO_T_CASHFLOW_VENTAS_HIST_DIA',          0, NULL, NULL, NULL, 1);

/* ---- Una seccion por tabla ---- */
DECLARE @tabla SYSNAME, @baja BIT, @copia VARCHAR(5), @fecha SYSNAME, @a SYSNAME,
        @soloAlta BIT, @sinDefault BIT, @obj NVARCHAR(300), @sql NVARCHAR(MAX),
        @col SYSNAME, @tipo NVARCHAR(30), @conDefault BIT, @n INT, @faltan INT = 0;

DECLARE @COLUMNAS TABLE (ORDEN INT, COL SYSNAME, TIPO NVARCHAR(30), CON_DEFAULT BIT, ES_BAJA BIT, ES_MODIF BIT);
INSERT INTO @COLUMNAS VALUES
    (1, 'USUARIO_ALTA',  'VARCHAR(50)', 0, 0, 0),
    (2, 'FECHA_ALTA',    'DATETIME',    1, 0, 0),
    (3, 'USUARIO_MODIF', 'VARCHAR(50)', 0, 0, 1),
    (4, 'FECHA_MODIF',   'DATETIME',    1, 0, 1),
    (5, 'USUARIO_BAJA',  'VARCHAR(50)', 0, 1, 0),
    (6, 'FECHA_BAJA',    'DATETIME',    0, 1, 0);

DECLARE t CURSOR LOCAL FAST_FORWARD FOR
    SELECT TABLA, BAJA, COPIA, FECHA, A, SOLO_ALTA, SIN_DEFAULT FROM #AUDITORIA ORDER BY ORDEN;
OPEN t;
FETCH NEXT FROM t INTO @tabla, @baja, @copia, @fecha, @a, @soloAlta, @sinDefault;

WHILE @@FETCH_STATUS = 0
BEGIN
    SET @obj = N'dbo.' + @tabla;
    PRINT '';
    PRINT '== ' + @tabla;

    IF OBJECT_ID(@obj, 'U') IS NULL
    BEGIN
        PRINT '   AVISO: la tabla no existe en esta base. Se sigue con las demas.';
        SET @faltan += 1;
    END
    ELSE
    BEGIN
        /* 1. Columnas */
        DECLARE c CURSOR LOCAL FAST_FORWARD FOR
            SELECT COL, TIPO, CON_DEFAULT FROM @COLUMNAS
            WHERE (ES_BAJA = 0 OR @baja = 1)
              AND ((ES_MODIF = 0 AND ES_BAJA = 0) OR @soloAlta = 0)
            ORDER BY ORDEN;
        OPEN c;
        FETCH NEXT FROM c INTO @col, @tipo, @conDefault;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            IF COL_LENGTH(@obj, @col) IS NULL
            BEGIN
                /* Sin WITH VALUES: el DEFAULT vale para las filas nuevas y las
                   existentes quedan NULL. */
                SET @sql = N'ALTER TABLE ' + @obj + N' ADD ' + QUOTENAME(@col) + N' ' + @tipo + N' NULL'
                    + CASE WHEN @conDefault = 1 AND @sinDefault = 0
                           THEN N' CONSTRAINT ' + QUOTENAME(N'DF_' + REPLACE(@tabla, N'RO_T_', N'') + N'_' + @col)
                                + N' DEFAULT (GETDATE())'
                           ELSE N'' END
                    + N';';
                EXEC sp_executesql @sql;
                PRINT '   + ' + @col;
            END
            ELSE
                PRINT '     ' + @col + ' ya estaba: se reusa.';

            FETCH NEXT FROM c INTO @col, @tipo, @conDefault;
        END

        CLOSE c;
        DEALLOCATE c;

        /* 2. Copia del usuario. En su propio lote dinamico: la columna puede
           haberse creado recien, y el compilador no la veria en este. */
        IF @copia IS NOT NULL AND COL_LENGTH(@obj, 'USUARIO') IS NOT NULL
        BEGIN
            SET @col = CASE @copia WHEN 'MODIF' THEN N'USUARIO_MODIF' ELSE N'USUARIO_ALTA' END;
            SET @sql = N'UPDATE ' + @obj + N' SET ' + @col + N' = LEFT(LTRIM(RTRIM(USUARIO)), 50)'
                + N' WHERE ' + @col + N' IS NULL AND NULLIF(LTRIM(RTRIM(USUARIO)), '''') IS NOT NULL;'
                + N' SET @n = @@ROWCOUNT;';
            EXEC sp_executesql @sql, N'@n INT OUTPUT', @n = @n OUTPUT;
            PRINT CONCAT('   USUARIO -> ', @col, ': ', @n, ' filas.');
        END

        /* 3. Copia de la fecha */
        IF @fecha IS NOT NULL AND COL_LENGTH(@obj, @fecha) IS NOT NULL
        BEGIN
            SET @sql = N'UPDATE ' + @obj + N' SET ' + QUOTENAME(@a) + N' = ' + QUOTENAME(@fecha)
                + N' WHERE ' + QUOTENAME(@a) + N' IS NULL AND ' + QUOTENAME(@fecha) + N' IS NOT NULL;'
                + N' SET @n = @@ROWCOUNT;';
            EXEC sp_executesql @sql, N'@n INT OUTPUT', @n = @n OUTPUT;
            PRINT CONCAT('   ', @fecha, ' -> ', @a, ': ', @n, ' filas.');
        END
    END

    FETCH NEXT FROM t INTO @tabla, @baja, @copia, @fecha, @a, @soloAlta, @sinDefault;
END

CLOSE t;
DEALLOCATE t;

PRINT '';
SET @n = (SELECT COUNT(*) FROM #AUDITORIA) - @faltan;
PRINT CONCAT('Listo: ', @n, ' tablas con auditoria, ', @faltan, ' que no existen en esta base.');

/* ---- Control: que columna de auditoria le falta a cada tabla ----
   Vacio = todo en orden. */
SELECT A.TABLA, C.COL AS FALTA
FROM #AUDITORIA A
CROSS JOIN @COLUMNAS C
WHERE OBJECT_ID(N'dbo.' + A.TABLA, 'U') IS NOT NULL
  AND (C.ES_BAJA = 0 OR A.BAJA = 1)
  AND ((C.ES_MODIF = 0 AND C.ES_BAJA = 0) OR A.SOLO_ALTA = 0)
  AND COL_LENGTH(N'dbo.' + A.TABLA, C.COL) IS NULL
ORDER BY A.ORDEN, C.ORDEN;

DROP TABLE #AUDITORIA;
GO
