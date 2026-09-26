/* ============================================================================
   MODULO CASHFLOW - PESTANA COB. ELECTRONICOS
   DDL de las tres tablas del modulo + semillas
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_COBEL_
   Orden   : se puede correr en cualquier momento. No depende de los otros
             scripts, aunque alimenta la fila 'Cobranzas Pagos Electronicos' que
             ya sembro sql/cashflow_estructura.sql y que
             sql/cashflow_estructura_disponibilidades.sql dejo en la seccion
             DISPONIBILIDADES.
   ----------------------------------------------------------------------------
   QUE CREA, EN ESTE ORDEN

     1. RO_T_CASHFLOW_COBEL_PROCESADORA  procesadoras de pago (parametro)
     2. RO_T_CASHFLOW_COBEL_ALICUOTA     retenciones por procesadora (parametro)
     3. RO_T_CASHFLOW_COBEL_MOVIMIENTO   acreditaciones informadas
     4. Semillas: Payway y Mercado Pago con IIBB 0,025 y SICREB 0,006

   NO crea ningun parametro clave/valor en RO_T_CASHFLOW_PARAMETROS: este modulo
   no tiene ninguno. Las alicuotas y las procesadoras son datos de negocio y
   viven en sus propias tablas, no en constantes del codigo.

   ----------------------------------------------------------------------------
   QUE ES ESTO

   Las acreditaciones que las procesadoras de pago (Payway, Mercado Pago) van a
   depositar en nuestro banco: el importe BRUTO que informan, la fecha en que lo
   van a acreditar, y el NETO que efectivamente entra despues de las retenciones
   impositivas de la procesadora.

       importe neto = importe bruto * (1 - tasa de retencion vigente)

   El neto y la tasa se PERSISTEN junto con el movimiento. La serie COBRANZA que
   consume el tablero es la suma de NETOS por fecha de acreditacion.

   ----------------------------------------------------------------------------
   LAS TRES PROPIEDADES QUE TIENE QUE CUMPLIR ESTE MODELO

   1. UN MOVIMIENTO NO PUEDE QUEDAR SIN NETO. En el Excel, D6, D7 y D24 no
      tienen formula: nunca calcularon neto, con 1.648.264,10 / 1.144.017,00 /
      43.150.368,26 de bruto. Aca TASA_APLICADA e IMPORTE_NETO son NOT NULL y
      los calcula el servidor al guardar; si la procesadora no tiene alicuotas
      vigentes a esa fecha, el alta se RECHAZA con el motivo. El neto nunca se
      tipea ni se acepta del navegador.

   2. EDITAR UN PORCENTAJE NO REESCRIBE LA HISTORIA. Una alicuota no se edita
      con un UPDATE sobre la fila vieja: se cierra la vigente y se INSERTA una
      nueva con VIGENCIA_DESDE. Por eso la tabla de alicuotas es un historico y
      no un parametro de una sola fila por concepto. Los movimientos YA
      ACREDITADOS conservan la TASA_APLICADA con la que se calcularon; los
      pendientes se recalculan (ver README-cob-electronicos.md).

      En el Excel, D8:D43 tienen *0.969 escrito a mano y solo las filas vacias
      usan $D$3: cambiar D3 no recalcula ni un movimiento. Esta tabla es lo que
      arregla las dos mitades de ese problema.

   3. LAS COLUMNAS DE LA IMPORTACION EXISTEN DESDE EL DIA UNO. Hoy la carga es
      manual; la importacion del archivo de la procesadora todavia NO esta
      hecha. ORIGEN_DATO, ID_EXTERNO y ARCHIVO_ORIGEN estan creadas igual y las
      dos ultimas quedan en NULL, para que enchufar la importacion no obligue a
      migrar datos. Mismo criterio que los campos de la API de Interbanking en
      sql/cashflow_saldos.sql.

   ----------------------------------------------------------------------------
   QUE NO SE MIGRA DEL EXCEL

   Las columnas E (copia de C) y F:AK (la matriz de vencimientos diaria y
   mensual) NO van al modelo: son la agrupacion contra el eje temporal y las
   deriva el proveedor. Los movimientos futuros de la hoja los carga
   sql/cashflow_cob_electronicos_migracion.sql, que va aparte.

   ----------------------------------------------------------------------------
   ES REEJECUTABLE
   Las tablas se crean solo si no existen y las semillas entran por MERGE
   WHEN NOT MATCHED, asi que una segunda corrida no duplica nada ni pisa un
   valor ya editado. Verificado corriendolo dos veces. La pantalla tampoco falla
   si el script no se corrio: muestra un aviso y el tablero deja la fila en
   cero, igual que hace hoy.

   NO HAY BAJAS FISICAS: todo se inhabilita con ACTIVO = 0.

   Todas las tablas llevan USUARIO VARCHAR(50) NULL. Todavia no hay login, por
   lo que se graba NULL; los metodos de guardado de PHP ya reciben $usuario.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_COBEL_PROCESADORA
   Las procesadoras de pago. Es un PARAMETRO: se administra desde
   Parametros -> Cob. Electronicos y no se carga en cada movimiento.

   RAZON_SOCIAL es la clave natural y es UNICA. La normalizacion es trim en PHP
   mas la comparacion case-insensitive que ya hace el collation por defecto de
   SQL Server: "payway" y "Payway" no pueden convivir como dos procesadoras
   distintas, porque despues cada una tendria su propio juego de alicuotas y los
   movimientos se repartirian entre las dos.

   UNA PROCESADORA NUEVA ENTRA INACTIVA (lo hace PHP, ver addProcesadora) y no
   se puede activar hasta que tenga al menos una alicuota vigente. Activarla
   vacia habilitaria altas de movimientos que despues no pueden calcular neto,
   que es exactamente la causa de que D6, D7 y D24 esten vacias en el Excel.

   Es un criterio DISTINTO al de una cuenta de Saldos, que nace activa: una
   cuenta no rompe ningun invariante y nace "sin cargar", que no informa de
   menos en silencio. Una procesadora sin alicuotas si rompe uno.

   Las dos procesadoras de la semilla entran ACTIVAS porque este mismo script
   les carga sus alicuotas unas lineas mas abajo, asi que el invariante se
   cumple desde el primer momento.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_PROCESADORA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBEL_PROCESADORA (
        ID           INT IDENTITY(1,1) NOT NULL,
        RAZON_SOCIAL VARCHAR(80) NOT NULL,
        ORDEN        INT         NOT NULL CONSTRAINT DF_CF_COBEL_PROC_ORDEN  DEFAULT (0),
        ACTIVO       BIT         NOT NULL CONSTRAINT DF_CF_COBEL_PROC_ACTIVO DEFAULT (0),
        FECHA_UPDATE DATETIME    NOT NULL CONSTRAINT DF_CF_COBEL_PROC_FUPD   DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50) NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_COBEL_PROCESADORA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_COBEL_PROCESADORA UNIQUE (RAZON_SOCIAL)
    );
END
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_COBEL_ALICUOTA
   Las retenciones impositivas que la procesadora descuenta de la acreditacion.
   Es un PARAMETRO y ademas un HISTORICO: cada edicion inserta una vigencia
   nueva.

   CONCEPTO NO ES UN ENUM CERRADO EN CODIGO. Hoy hay dos, 'IIBB' y 'SICREB',
   que son las dos que estaban escondidas en la celda D3 del Excel
   (=1-0.025-0.006). Manana puede aparecer una tercera retencion, y agregarla
   tiene que ser cargar una fila y no tocar un CHECK ni un archivo PHP. Por eso
   no hay CHECK sobre CONCEPTO: la formula suma lo que haya.

   VIGENCIA_DESDE NO ES "POR SI ACASO": es lo que permite editar un porcentaje
   sin reescribir la historia. La tasa de un movimiento se resuelve contra su
   FECHA_ACREDITACION, tomando de cada CONCEPTO la ultima vigencia con
   VIGENCIA_DESDE <= esa fecha. Sin la fecha, cambiar un porcentaje cambiaria
   retroactivamente el neto de todo lo ya informado.

   NO HAY UNIQUE (ID_PROCESADORA, CONCEPTO, VIGENCIA_DESDE) A PROPOSITO. Nada
   impide corregir dos veces el mismo porcentaje el mismo dia -es justo lo que
   pasa cuando alguien se equivoca al tipearlo-, y con un UNIQUE la segunda
   correccion fallaria con un error de indice. El desempate entre dos vigencias
   de la misma fecha es por ID, que es un IDENTITY: gana siempre la insertada
   despues. Es la misma regla que Saldos::ultimaCarga() y esta cubierta por las
   pruebas.

   LA SUMA DE LAS ALICUOTAS VIGENTES DE UNA PROCESADORA TIENE QUE SER < 1. Con
   suma >= 1 el neto saldria cero o negativo, o sea que una acreditacion
   restaria plata del tablero. Se valida AL GUARDAR la alicuota y no al usarla:
   el momento de frenarlo es cuando alguien la escribe, no cuando un movimiento
   ya quedo mal calculado. El CHECK de la columna acota cada alicuota; la suma
   la valida PHP, porque depende de las otras filas vigentes.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_ALICUOTA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBEL_ALICUOTA (
        ID             INT IDENTITY(1,1) NOT NULL,
        ID_PROCESADORA INT           NOT NULL,
        CONCEPTO       VARCHAR(30)   NOT NULL,
        ALICUOTA       DECIMAL(9,6)  NOT NULL,
        VIGENCIA_DESDE DATE          NOT NULL,
        ACTIVO         BIT           NOT NULL CONSTRAINT DF_CF_COBEL_ALIC_ACTIVO DEFAULT (1),
        FECHA_UPDATE   DATETIME      NOT NULL CONSTRAINT DF_CF_COBEL_ALIC_FUPD   DEFAULT (GETDATE()),
        USUARIO        VARCHAR(50)   NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_COBEL_ALICUOTA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_COBEL_ALICUOTA_PROC
            FOREIGN KEY (ID_PROCESADORA) REFERENCES dbo.RO_T_CASHFLOW_COBEL_PROCESADORA (ID),
        /* Una alicuota individual nunca puede llegar a 1: el neto se iria a
           cero de una sola retencion. La suma de todas la valida PHP. */
        CONSTRAINT CK_RO_T_CASHFLOW_COBEL_ALICUOTA_RANGO
            CHECK (ALICUOTA >= 0 AND ALICUOTA < 1)
    );

    /* Es el indice de "dame las alicuotas vigentes de esta procesadora a esta
       fecha", que corre en cada alta de movimiento y en cada calculo. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_COBEL_ALICUOTA_VIGENCIA
        ON dbo.RO_T_CASHFLOW_COBEL_ALICUOTA (ID_PROCESADORA, CONCEPTO, VIGENCIA_DESDE DESC, ID DESC)
        INCLUDE (ALICUOTA, ACTIVO);
END
GO

/* ----------------------------------------------------------------------------
   3. RO_T_CASHFLOW_COBEL_MOVIMIENTO
   Las acreditaciones. Una fila por liquidacion informada por la procesadora.

   Origen en el Excel:
       ID_PROCESADORA      col A  RAZON_SOC
       IMPORTE_BRUTO       col B  Importe          <- dato de entrada
       FECHA_ACREDITACION  col C  Cobro            <- dato de entrada
       TASA_APLICADA       celda D3                <- PERSISTIDA
       IMPORTE_NETO        col D                   <- PERSISTIDO, calculado

   IMPORTE_NETO Y TASA_APLICADA SON NOT NULL Y NO SE TIPEAN. Los calcula el
   servidor al guardar, con la tasa vigente a la FECHA_ACREDITACION -no a la
   fecha de carga-. Aceptarlos del navegador permitiria guardar cualquier numero
   como si fuera el calculado, que es la version informatica del *0.969 escrito
   a mano.

   Que la tasa quede persistida es lo que hace que editar un porcentaje no
   reescriba lo ya guardado.

   DOS MOVIMIENTOS DE LA MISMA PROCESADORA Y LA MISMA FECHA SE AVISAN, NO SE
   BLOQUEAN: puede haber dos liquidaciones el mismo dia. Por eso no hay UNIQUE
   (ID_PROCESADORA, FECHA_ACREDITACION). El aviso alcanza para detectar el
   pegado doble, que es el error real que se quiere atrapar.

   ID_EXTERNO va con INDICE UNICO FILTRADO (WHERE ID_EXTERNO IS NOT NULL), igual
   que el CBU de Saldos: es la clave con la que la importacion va a reconocer un
   movimiento ya cargado a mano y no duplicarlo. Filtrado porque hoy todas las
   filas lo tienen en NULL, y un UNIQUE comun de SQL Server admite un solo NULL.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO (
        ID                 INT IDENTITY(1,1) NOT NULL,
        ID_PROCESADORA     INT           NOT NULL,
        IMPORTE_BRUTO      DECIMAL(19,4) NOT NULL,
        FECHA_ACREDITACION DATE          NOT NULL,

        /* La tasa con la que se calculo el neto, guardada con el movimiento:
           es lo que permite editar un porcentaje sin tocar lo ya informado. */
        TASA_APLICADA      DECIMAL(9,6)  NOT NULL,
        IMPORTE_NETO       DECIMAL(19,4) NOT NULL,

        /* -- Columnas de la importacion. Hoy: 'MANUAL' y las otras dos NULL. -- */
        ORIGEN_DATO        VARCHAR(10)   NOT NULL CONSTRAINT DF_CF_COBEL_MOV_ORIGEN DEFAULT ('MANUAL'),
        ID_EXTERNO         VARCHAR(60)   NULL,   -- id de la liquidacion en la procesadora
        ARCHIVO_ORIGEN     VARCHAR(120)  NULL,   -- nombre del archivo importado

        OBSERVACIONES      VARCHAR(200)  NULL,
        ACTIVO             BIT           NOT NULL CONSTRAINT DF_CF_COBEL_MOV_ACTIVO DEFAULT (1),
        FECHA_ALTA         DATETIME      NOT NULL CONSTRAINT DF_CF_COBEL_MOV_FALTA  DEFAULT (GETDATE()),
        FECHA_UPDATE       DATETIME      NOT NULL CONSTRAINT DF_CF_COBEL_MOV_FUPD   DEFAULT (GETDATE()),
        USUARIO            VARCHAR(50)   NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_COBEL_MOVIMIENTO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_COBEL_MOVIMIENTO_PROC
            FOREIGN KEY (ID_PROCESADORA) REFERENCES dbo.RO_T_CASHFLOW_COBEL_PROCESADORA (ID),
        CONSTRAINT CK_RO_T_CASHFLOW_COBEL_MOVIMIENTO_BRUTO
            CHECK (IMPORTE_BRUTO > 0),
        CONSTRAINT CK_RO_T_CASHFLOW_COBEL_MOVIMIENTO_TASA
            CHECK (TASA_APLICADA >= 0 AND TASA_APLICADA < 1),
        CONSTRAINT CK_RO_T_CASHFLOW_COBEL_MOVIMIENTO_ORIGEN
            CHECK (ORIGEN_DATO IN ('MANUAL', 'ARCHIVO', 'API'))
    );

    /* La consulta del proveedor y del cuadro diario: netos activos por fecha. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_COBEL_MOVIMIENTO_FECHA
        ON dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO (ACTIVO, FECHA_ACREDITACION)
        INCLUDE (ID_PROCESADORA, IMPORTE_BRUTO, IMPORTE_NETO, TASA_APLICADA);

    /* El identificador de la liquidacion en la procesadora. Filtrado porque hoy
       esta en NULL en todas las filas. Es lo que va a evitar que la importacion
       duplique un movimiento ya cargado a mano. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CASHFLOW_COBEL_MOVIMIENTO_EXTERNO
        ON dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO (ID_PROCESADORA, ID_EXTERNO)
        WHERE ID_EXTERNO IS NOT NULL;
END
GO

/* ----------------------------------------------------------------------------
   4a. Semilla de las procesadoras
   Son las dos que aparecen en la columna A del Excel. Entran ACTIVAS porque el
   bloque siguiente les carga sus alicuotas: una procesadora activa sin
   alicuotas vigentes es justamente lo que el modulo no permite.
   ---------------------------------------------------------------------------- */
MERGE dbo.RO_T_CASHFLOW_COBEL_PROCESADORA AS T
USING (VALUES
    ('Payway', 10),
    ('Mercado Pago', 20)
) AS S (RAZON_SOCIAL, ORDEN)
    ON T.RAZON_SOCIAL = S.RAZON_SOCIAL
WHEN NOT MATCHED BY TARGET THEN
    INSERT (RAZON_SOCIAL, ORDEN, ACTIVO)
    VALUES (S.RAZON_SOCIAL, S.ORDEN, 1);
GO

/* ----------------------------------------------------------------------------
   4b. Semilla de las alicuotas
   IIBB 0,025 y SICREB 0,006 para las dos procesadoras: son las dos que estaban
   escondidas en la celda D3 del Excel (=1-0.025-0.006 = 0,969).

   VIGENCIA_DESDE es LA FECHA EN QUE SE CORRE EL SCRIPT y no una fecha fija: la
   hoja no dice desde cuando rigen esos porcentajes, y poner una fecha inventada
   haria que el modulo afirmara algo que nadie sabe. Desde hoy en adelante si es
   cierto.

   El MERGE empareja por (procesadora, concepto), no por vigencia: una segunda
   corrida del script no agrega una vigencia nueva ni pisa una que el usuario ya
   edito desde Parametros.
   ---------------------------------------------------------------------------- */
MERGE dbo.RO_T_CASHFLOW_COBEL_ALICUOTA AS T
USING (
    SELECT P.ID AS ID_PROCESADORA, A.CONCEPTO, A.ALICUOTA
    FROM dbo.RO_T_CASHFLOW_COBEL_PROCESADORA P
    CROSS JOIN (VALUES
        ('IIBB',   0.025000),
        ('SICREB', 0.006000)
    ) AS A (CONCEPTO, ALICUOTA)
    WHERE P.RAZON_SOCIAL IN ('Payway', 'Mercado Pago')
) AS S
    ON T.ID_PROCESADORA = S.ID_PROCESADORA AND T.CONCEPTO = S.CONCEPTO
WHEN NOT MATCHED BY TARGET THEN
    INSERT (ID_PROCESADORA, CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ACTIVO)
    VALUES (S.ID_PROCESADORA, S.CONCEPTO, S.ALICUOTA, CAST(GETDATE() AS DATE), 1);
GO

/* ----------------------------------------------------------------------------
   Control: la suma de las alicuotas vigentes de cada procesadora tiene que ser
   menor a 1. Si no, sus movimientos darian neto cero o negativo. Se imprime en
   vez de fallar, porque el script puede correrse sobre datos ya editados y el
   diagnostico es mas util que un error.
   ---------------------------------------------------------------------------- */
WITH Vigente AS (
    SELECT A.ID_PROCESADORA, A.CONCEPTO, A.ALICUOTA,
           ROW_NUMBER() OVER (
               PARTITION BY A.ID_PROCESADORA, A.CONCEPTO
               ORDER BY A.VIGENCIA_DESDE DESC, A.ID DESC
           ) AS RN
    FROM dbo.RO_T_CASHFLOW_COBEL_ALICUOTA A
    WHERE A.ACTIVO = 1 AND A.VIGENCIA_DESDE <= CAST(GETDATE() AS DATE)
)
SELECT P.RAZON_SOCIAL,
       P.ACTIVO,
       COUNT(V.CONCEPTO)          AS CONCEPTOS_VIGENTES,
       ISNULL(SUM(V.ALICUOTA), 0) AS TASA_TOTAL,
       CASE WHEN ISNULL(SUM(V.ALICUOTA), 0) >= 1 THEN 'REVISAR: el neto seria cero o negativo'
            WHEN COUNT(V.CONCEPTO) = 0 THEN 'REVISAR: sin alicuotas vigentes, no admite movimientos'
            ELSE 'OK' END         AS ESTADO
FROM dbo.RO_T_CASHFLOW_COBEL_PROCESADORA P
LEFT JOIN Vigente V ON V.ID_PROCESADORA = P.ID AND V.RN = 1
GROUP BY P.RAZON_SOCIAL, P.ACTIVO
ORDER BY P.RAZON_SOCIAL;
GO

PRINT 'Modulo Cob. Electronicos: tablas y semillas listas.';
GO
