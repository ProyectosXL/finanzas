/* ============================================================================
   MODULO CASHFLOW - ESTRUCTURA DEL TABLERO
   DDL de tablas de configuracion + carga de semillas
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_
   Orden   : se puede correr en cualquier momento; no depende de los otros
             scripts, aunque comparte RO_T_CASHFLOW_PARAMETROS con Ventas.
   ----------------------------------------------------------------------------
   QUE ES ESTO
   La pantalla Cashflow no tiene ninguna fila ni seccion escrita en el codigo:
   toda su estructura sale de estas dos tablas. Agregar, renombrar, reordenar o
   inhabilitar una fila es un cambio de datos, no de codigo.

   Cada fila de movimiento se alimenta de un PROVEEDOR: un modulo del sistema
   que expone sus importes por fecha a traves de un contrato comun. La pantalla
   no sabe como calcula cada modulo; solo consume la serie que le devuelve.

   Los codigos validos de ORIGEN_PROVIDER / ORIGEN_SERIE los declara el
   registro de proveedores en cashflow/Class/CashflowRegistry.php. Los que
   corresponden a modulos que todavia no existen quedan marcados alli con
   'disponible' => false: su fila se muestra en cero y el tablero avisa, en vez
   de esconder la fila. Es el mismo criterio de RO_T_CASHFLOW_VENTAS_PRECHEQ:
   cablear el camino completo devolviendo cero y documentar el pendiente.

   Todas las tablas llevan USUARIO VARCHAR(50) NULL. Todavia no hay login en la
   aplicacion, por lo que se graba NULL; los metodos de guardado de PHP ya
   reciben el parametro $usuario para cuando exista.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_CONF_SECCION
   Secciones del tablero (Disponible Inicial, Ingresos, Costos, Resultados...).

   ROL define como participa la seccion en el calculo, y es lo que evita tener
   que escribir un lenguaje de formulas:
       'SALDO'      -> aporta saldo inicial (arranca el arrastre)
       'MOVIMIENTO' -> aporta flujo (ingresos y egresos)
       'DERIVADO'   -> no aporta nada; contiene filas calculadas

   ID_PADRE permite colgar una seccion de otra. Sirve para que un SUBTOTAL
   abarque varias secciones (por ejemplo un "Total Egresos" sobre Costo de
   Mercaderia + Directos + Indirectos) sin tocar el codigo: alcanza con
   reparentar las tres y poner el SUBTOTAL en la seccion madre. La semilla
   arranca plana, con todos los ID_PADRE en NULL.

   La clave primaria es CODIGO y no un IDENTITY, a diferencia de CONF_FILA.
   Es a proposito: son pocas filas, se referencian por codigo desde
   CONF_FILA.SECCION, y asi esa referencia es una clave foranea de verdad.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_SECCION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_CONF_SECCION (
        CODIGO       VARCHAR(30)  NOT NULL,
        NOMBRE       VARCHAR(80)  NOT NULL,
        ROL          VARCHAR(15)  NOT NULL,
        ID_PADRE     VARCHAR(30)  NULL,
        ORDEN        INT          NOT NULL CONSTRAINT DF_CF_SECCION_ORDEN  DEFAULT (0),
        ACTIVO       BIT          NOT NULL CONSTRAINT DF_CF_SECCION_ACTIVO DEFAULT (1),
        FECHA_UPDATE DATETIME     NOT NULL CONSTRAINT DF_CF_SECCION_FUPD   DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50)  NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_CONF_SECCION PRIMARY KEY CLUSTERED (CODIGO),
        CONSTRAINT CK_RO_T_CASHFLOW_CONF_SECCION_ROL
            CHECK (ROL IN ('SALDO', 'MOVIMIENTO', 'DERIVADO')),
        CONSTRAINT FK_RO_T_CASHFLOW_CONF_SECCION_PADRE
            FOREIGN KEY (ID_PADRE) REFERENCES dbo.RO_T_CASHFLOW_CONF_SECCION (CODIGO)
    );
END
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_CONF_FILA
   Filas del tablero.

   TIPO define el signo y el significado de la fila. Las tres primeras traen
   datos de un proveedor; las tres ultimas las calcula el motor:
       'SALDO_INICIAL' -> saldo de apertura; arranca el arrastre
       'INGRESO'       -> suma  (+1)
       'EGRESO'        -> resta (-1)
       'SUBTOTAL'      -> suma las filas de movimiento de su propia seccion y
                          de las secciones hijas
       'FLUJO_NETO'    -> suma todas las filas de movimiento de las secciones
                          ROL='MOVIMIENTO' que esten POR ENCIMA de ella
       'SALDO_FINAL'   -> lo mismo que FLUJO_NETO, mas las secciones ROL='SALDO'

   El alcance de las filas calculadas es POSICIONAL: se resuelve con
   (SECCION.ORDEN, FILA.ORDEN). No hay ninguna referencia fila->fila guardada,
   asi que no puede haber referencias colgadas ni ciclos.

   No hay columna SIGNO: el signo lo determina TIPO. Una columna aparte
   permitiria configurar "un Ingreso que resta", que no significa nada.

   COMPUTA y ACTIVO son dos indicadores independientes:
       ACTIVO  = 0 -> la fila no aparece en el tablero (baja logica; nunca se
                      borra un registro, igual que en RO_T_CASHFLOW_VENTAS_MIX)
       COMPUTA = 0 -> la fila aparece pero queda fuera de toda suma
   COMPUTA=0 es lo que resuelve la fila "Ventas" del Excel: es un ingreso que
   NO es caja (la caja es la fila "Cobros"), y contarla seria duplicar. Se
   modela como INGRESO con COMPUTA=0 y no como un tipo aparte, para que
   conserve el signo y el formato de las filas de su seccion.

   Aca la clave primaria SI es un IDENTITY, a diferencia de CONF_SECCION: el
   editor direcciona las filas por data-id (mismo patron que el mix de cobro) y
   las filas se crean y se renombran desde la pantalla. CODIGO es la clave
   natural y va con UNIQUE.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_CONF_FILA (
        ID              INT IDENTITY(1,1) NOT NULL,
        CODIGO          VARCHAR(30)  NOT NULL,
        NOMBRE          VARCHAR(80)  NOT NULL,
        SECCION         VARCHAR(30)  NOT NULL,
        TIPO            VARCHAR(20)  NOT NULL,
        COMPUTA         BIT          NOT NULL CONSTRAINT DF_CF_FILA_COMPUTA DEFAULT (1),
        ORIGEN_PROVIDER VARCHAR(30)  NULL,
        ORIGEN_SERIE    VARCHAR(30)  NULL,
        ORDEN           INT          NOT NULL CONSTRAINT DF_CF_FILA_ORDEN   DEFAULT (0),
        ACTIVO          BIT          NOT NULL CONSTRAINT DF_CF_FILA_ACTIVO  DEFAULT (1),
        FECHA_UPDATE    DATETIME     NOT NULL CONSTRAINT DF_CF_FILA_FUPD    DEFAULT (GETDATE()),
        USUARIO         VARCHAR(50)  NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_CONF_FILA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_CONF_FILA UNIQUE (CODIGO),
        CONSTRAINT CK_RO_T_CASHFLOW_CONF_FILA_TIPO
            CHECK (TIPO IN ('SALDO_INICIAL', 'INGRESO', 'EGRESO',
                            'SUBTOTAL', 'FLUJO_NETO', 'SALDO_FINAL')),
        CONSTRAINT FK_RO_T_CASHFLOW_CONF_FILA_SECCION
            FOREIGN KEY (SECCION) REFERENCES dbo.RO_T_CASHFLOW_CONF_SECCION (CODIGO)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_CONF_FILA_ORDEN
        ON dbo.RO_T_CASHFLOW_CONF_FILA (ACTIVO, SECCION, ORDEN)
        INCLUDE (CODIGO, NOMBRE, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE);
END
GO

/* ----------------------------------------------------------------------------
   3. Semilla de secciones
   Las siete secciones del Excel original. ORDEN va de diez en diez: el
   guardado del editor renumera desde cero con (indice+1)*10, asi que dejar
   huecos no molesta y hace mas legible un alta manual.
   ---------------------------------------------------------------------------- */
MERGE dbo.RO_T_CASHFLOW_CONF_SECCION AS T
USING (VALUES
    ('DISPONIBLE_INICIAL', 'Disponible Inicial',   'SALDO',      10),
    ('INGRESOS',           'Ingresos',             'MOVIMIENTO', 20),
    ('COSTO_MERCADERIA',   'Costo de Mercaderia',  'MOVIMIENTO', 30),
    ('COSTOS_DIRECTOS',    'Costos Directos',      'MOVIMIENTO', 40),
    ('COSTOS_INDIRECTOS',  'Costos Indirectos',    'MOVIMIENTO', 50),
    ('AJUSTES',            'Ajustes',              'MOVIMIENTO', 60),
    ('RESULTADOS',         'Resultados',           'DERIVADO',   70)
) AS S (CODIGO, NOMBRE, ROL, ORDEN)
    ON T.CODIGO = S.CODIGO
WHEN NOT MATCHED BY TARGET THEN
    INSERT (CODIGO, NOMBRE, ROL, ID_PADRE, ORDEN, ACTIVO)
    VALUES (S.CODIGO, S.NOMBRE, S.ROL, NULL, S.ORDEN, 1);
GO

/* ----------------------------------------------------------------------------
   4. Semilla de filas
   Reproduce la estructura del Excel. Las filas cuyo modulo todavia no existe
   entran ACTIVAS a proposito: el tablero muestra la forma completa del Excel y
   avisa cuales estan en cero, en vez de esconder la mitad del cuadro. Para
   sacarlas de la pantalla alcanza con inhabilitarlas desde Parametros.

   ORIGEN_PROVIDER con datos reales hoy: VENTAS, COBRANZAS_FR,
   COMEX_PROV_EXT y COMEX_NAC. El resto son proveedores declarados en el
   registro con 'disponible' => false.

   Nota sobre COBROS_VENTAS y COBRANZAS_FR: NO se pisan entre si. Ventas
   proyecta cobranza de ventas FUTURAS y Cobranzas FR trae cobranza REAL de
   facturas ya emitidas. Se suman a proposito; el invariante esta enunciado en
   el encabezado de cashflow/Class/Ventas.php:
       Cobranza total = cobranza real + cobranza sobre ventas estimadas
   ---------------------------------------------------------------------------- */
MERGE dbo.RO_T_CASHFLOW_CONF_FILA AS T
USING (VALUES
    /* CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, PROVIDER, SERIE, ORDEN */

    /* -- Disponible Inicial -------------------------------------------------- */
    ('DISPONIBLE', 'Disponible', 'DISPONIBLE_INICIAL', 'SALDO_INICIAL', 1,
        'SALDOS', 'DISPONIBLE', 10),

    /* -- Ingresos ------------------------------------------------------------ */
    /* Informativa: es venta, no es caja. La caja es COBROS_VENTAS. */
    ('VENTAS', 'Ventas (informativa, no es caja)', 'INGRESOS', 'INGRESO', 0,
        'VENTAS', 'VENTA', 10),
    ('COBROS_VENTAS', 'Cobros s/ ventas estimadas', 'INGRESOS', 'INGRESO', 1,
        'VENTAS', 'COBRANZA', 20),
    /* La cobranza de franquicias va PARTIDA en dos filas y no en una sola con
       la serie COBRANZA (que es real + proyectada sumadas): son dos cosas que
       se miran distinto -lo comprometido en propuestas ACEPTADAS y lo estimado
       por PPP- y en un solo numero no se distinguen. La fila total
       COBRANZAS_FR queda declarada e INACTIVA: el registro la relaciona con
       estas dos en su bloque 'componentes', asi que activarla junto a ellas
       contaria dos veces el mismo importe y el validador lo rechaza.
       Ver sql/cashflow_estructura_split_cobranzas_fr.sql. */
    ('COBRANZAS_FR_REAL', 'Cobranzas Franquicias (Prop. aceptadas)', 'INGRESOS', 'INGRESO', 1,
        'COBRANZAS_FR', 'COBRANZA_REAL', 30),
    ('COBRANZAS_FR_PROY', 'Cobranzas Franquicias Proyectadas', 'INGRESOS', 'INGRESO', 1,
        'COBRANZAS_FR', 'COBRANZA_PROYECTADA', 35),
    ('COBRANZAS_MAY', 'Cobranzas Mayoristas', 'INGRESOS', 'INGRESO', 1,
        'COBRANZAS_MAY', 'COBRANZA', 40),
    ('COB_ELECTRONICOS', 'Cobranzas Electronicas', 'INGRESOS', 'INGRESO', 1,
        'COB_ELECTRONICOS', 'COBRANZA', 50),
    ('ECHEQS', 'Echeqs a cobrar', 'INGRESOS', 'INGRESO', 1,
        'ECHEQS', 'A_COBRAR', 60),
    ('SUB_INGRESOS', 'Total Ingresos', 'INGRESOS', 'SUBTOTAL', 0,
        NULL, NULL, 70),

    /* -- Costo de Mercaderia ------------------------------------------------- */
    ('PROV_EXTERIOR', 'Proveedores Exterior', 'COSTO_MERCADERIA', 'EGRESO', 1,
        'COMEX_PROV_EXT', 'PAGOS', 10),
    ('NACIONALIZACIONES', 'Nacionalizaciones', 'COSTO_MERCADERIA', 'EGRESO', 1,
        'COMEX_NAC', 'NACIONALIZACION', 20),
    ('PROV_LOCALES', 'Proveedores Locales', 'COSTO_MERCADERIA', 'EGRESO', 1,
        'PROV_LOCALES', 'PAGOS', 30),
    ('SUB_COSTO_MERCADERIA', 'Total Costo de Mercaderia', 'COSTO_MERCADERIA', 'SUBTOTAL', 0,
        NULL, NULL, 40),

    /* -- Costos Directos ----------------------------------------------------- */
    ('LOGISTICA', 'Logistica', 'COSTOS_DIRECTOS', 'EGRESO', 1,
        'LOGISTICA', 'PAGOS', 10),
    ('SUB_COSTOS_DIRECTOS', 'Total Costos Directos', 'COSTOS_DIRECTOS', 'SUBTOTAL', 0,
        NULL, NULL, 20),

    /* -- Costos Indirectos --------------------------------------------------- */
    ('HABERES', 'Haberes', 'COSTOS_INDIRECTOS', 'EGRESO', 1,
        'HABERES', 'PAGOS', 10),
    ('ALQUILERES', 'Alquileres', 'COSTOS_INDIRECTOS', 'EGRESO', 1,
        'ALQUILERES', 'PAGOS', 20),
    ('LLAVES_RENOV', 'Llaves y Renovaciones', 'COSTOS_INDIRECTOS', 'EGRESO', 1,
        'LLAVES_RENOV', 'PAGOS', 30),
    ('IMPUESTOS', 'Impuestos', 'COSTOS_INDIRECTOS', 'EGRESO', 1,
        'IMPUESTOS', 'PAGOS', 40),
    ('SUB_COSTOS_INDIRECTOS', 'Total Costos Indirectos', 'COSTOS_INDIRECTOS', 'SUBTOTAL', 0,
        NULL, NULL, 50),

    /* -- Ajustes ------------------------------------------------------------- */
    ('FINANCIERO', 'Financiero', 'AJUSTES', 'EGRESO', 1,
        'FINANCIERO', 'MOVIMIENTOS', 10),
    ('OTROS', 'Otros', 'AJUSTES', 'EGRESO', 1,
        'OTROS', 'MOVIMIENTOS', 20),
    ('SUB_AJUSTES', 'Total Ajustes', 'AJUSTES', 'SUBTOTAL', 0,
        NULL, NULL, 30),

    /* -- Resultados ---------------------------------------------------------- */
    ('FLUJO_NETO', 'Flujo Neto', 'RESULTADOS', 'FLUJO_NETO', 0,
        NULL, NULL, 10),
    ('SALDO_FINAL', 'Saldo Final', 'RESULTADOS', 'SALDO_FINAL', 0,
        NULL, NULL, 20)

) AS S (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN)
    ON T.CODIGO = S.CODIGO
WHEN NOT MATCHED BY TARGET THEN
    INSERT (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES (S.CODIGO, S.NOMBRE, S.SECCION, S.TIPO, S.COMPUTA,
            S.ORIGEN_PROVIDER, S.ORIGEN_SERIE, S.ORDEN, 1);
GO

/* ----------------------------------------------------------------------------
   5. Parametros del modulo Cashflow
   Se cargan en la tabla generica RO_T_CASHFLOW_PARAMETROS que ya usa Ventas.
   El horizonte (horizonte_dias / horizonte_meses) NO se duplica: el Cashflow
   usa el mismo eje temporal que la proyeccion de Ventas.

   comex_tipo_cambio_usd vive en MODULO='COMEX' y no en 'CASHFLOW' porque la
   conversion la hace el proveedor de Comex, no el motor: el tipo de cambio de
   un pago futuro es criterio de negocio de Comercio Exterior. El motor nunca
   ve dolares, todos los proveedores le entregan pesos.

   OJO: el valor sembrado es de referencia y hay que actualizarlo. La pantalla
   muestra en el detalle de cada fila el tipo de cambio con el que se valuo,
   para que no quede oculto que importe se convirtio y a cuanto.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NOT NULL
BEGIN
    MERGE dbo.RO_T_CASHFLOW_PARAMETROS AS T
    USING (VALUES
        ('comex_tipo_cambio_usd', '1000.0000', 'DECIMAL',
         'Tipo de cambio USD->ARS para valuar pagos al exterior. ACTUALIZAR: valor inicial de referencia',
         'COMEX', 'GENERAL')
    ) AS S (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO)
        ON T.CLAVE = S.CLAVE
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, MODULO, GRUPO)
        VALUES (S.CLAVE, S.VALOR, S.TIPO_DATO, S.DESCRIPCION, S.MODULO, S.GRUPO);
END
GO
