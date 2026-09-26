/* ============================================================================
   MODULO CASHFLOW - LAS CUENTAS DE INVERSION Y COMITENTE SON CUENTAS DE SALDOS
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_saldos.sql (crea el catalogo de cuentas),
             de sql/cashflow_cobertura.sql y sql/cashflow_cobertura_por_fondo.sql
             (la tabla de aplicaciones, con su columna MONEDA) y de
             sql/cashflow_dolares_comitente_cobertura.sql (la fila de stock de
             dolares). Si faltan las tablas de Otros Ingresos, la migracion de
             datos se saltea y lo dice: no son obligatorias.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   Hasta ahora el stock de la seccion Cobertura salia de dos FOTOS del saldo
   cargadas en Otros Ingresos: RO_T_CASHFLOW_SALDO_INVERSIONES (pesos) y
   RO_T_CASHFLOW_DOLARES_COMITENTE (dolares). Cada carga era "cuanto hay hoy",
   y el tablero se quedaba con la ultima.

   Ahora los fondos son CUENTAS del catalogo de Saldos, con cuenta corriente
   propia:

       saldo a una fecha = saldo inicial + suscripciones - rescates
                           (movimientos vigentes con FECHA <= esa fecha)

   Las tablas de Otros Ingresos NO se borran: quedan por el historico, igual
   que cuando el saldo de inversiones dejo de ser un ingreso. Las PESTANAS si
   se eliminaron -son codigo, no datos-: una pantalla que abre y guarda, y cuyo
   numero ya no va a ningun lado, confunde aunque lleve un cartel.

   ----------------------------------------------------------------------------
   1. CLASE ES UNA COLUMNA NUEVA, Y NO UN VALOR MAS DE TIPO

   RO_T_CASHFLOW_SALDOS_CUENTA.TIPO ya existe y dice DE DONDE SALE el saldo:
   BANCO (manual hoy, Interbanking manana), MERCADO_PAGO, EFECTIVO_CENTRAL (una
   consulta sobre SBA05) u OTRO. Es lo que decide ORIGEN_DATO, lo que la API va
   a sincronizar, el filtro de la pestana Saldos, y es INMUTABLE despues del
   alta porque el historico de cada cuenta esta atribuido a ese origen.

   CLASE dice QUE ES la cuenta, que es otra pregunta:

       'CTA_CORRIENTE' | 'CAJA_AHORRO'   plata a la vista: es DISPONIBILIDAD, se
                                         carga como foto del saldo y entra al
                                         Saldo Inicial del tablero.
       'INVERSION' | 'COMITENTE'         un FONDO: lleva cuenta corriente y es
                                         STOCK DE COBERTURA. No entra en
                                         Disponibilidades, porque la misma plata
                                         se contaria dos veces.

   Se descarto reorganizar TIPO por tres motivos:
     - Son dos ejes independientes. Un banco tiene cuentas corrientes Y cajas de
       ahorro, y un comitente puede estar en pesos o en dolares; una sola
       columna obligaria a inventar BANCO_CC, BANCO_CA, OTRO_COMITENTE...
     - TIPO ya esta escrito en el historico y en el codigo que decide el origen
       del dato; renombrar sus valores seria una migracion sin ganancia.
     - ACCOUNT_TYPE ('CC'/'CA') es la vision de Interbanking del mismo dato,
       solo para cuentas bancarias y solo cuando la API lo traiga. CLASE es la
       del negocio y vale para todas las cuentas; el dia que la API sincronice,
       CLASE de una cuenta bancaria se deriva de ACCOUNT_TYPE.

   LAS CUENTAS QUE YA ESTAN QUEDAN COMO 'CTA_CORRIENTE'. Es lo que son todas hoy
   -bancos, Mercado Pago y el efectivo de tesoreria son plata a la vista- y es el
   default de la columna, asi que el ADD las rellena solo. Si alguna es una caja
   de ahorro, se corrige desde Parametros -> Saldos: cambiar entre las dos
   clases a la vista no toca ningun historico.

   Los fondos llevan TIPO = 'OTRO': un fondo no es un banco ni una billetera y
   su saldo no sale de ninguna consulta ni de ninguna API, sale de su cuenta
   corriente. Que es un fondo lo dice CLASE.

   ----------------------------------------------------------------------------
   2. EL SALDO INICIAL VA EN LA CUENTA

   SALDO_INICIAL y FECHA_SALDO_INICIAL son dos columnas de la cuenta y no un
   movimiento mas: es un parametro de la cuenta, como el nombre, que se fija al
   darla de alta (o en esta migracion) y del que arranca la cuenta corriente.
   Los eventos son los movimientos; el saldo inicial es el punto de partida.

   Es el saldo AL CIERRE de esa fecha: los movimientos de esa fecha o anteriores
   ya estan incluidos en el, y por eso Fondos::saldoA() no los suma. Sin esa
   regla, fijar un saldo inicial nuevo despues de haber cargado movimientos los
   contaria dos veces.

   Corregirlo es un UPDATE auditado (FECHA_UPDATE, USUARIO), no una fila nueva:
   a diferencia de un movimiento, no describe un hecho sino el punto desde el
   que se cuenta. Va en NULL mientras la cuenta no sea un fondo.

   ----------------------------------------------------------------------------
   3. LOS MOVIMIENTOS TIENEN HISTORIAL, COMO LAS APLICACIONES DE COBERTURA

   RO_T_CASHFLOW_SALDOS_FONDO_MOV guarda cada suscripcion y cada rescate. Es el
   mismo circuito que RO_T_CASHFLOW_COBERTURA_APLIC: editar un movimiento NO hace
   UPDATE, marca el anterior con VIGENTE = 0 e inserta uno nuevo que apunta al
   que reemplaza (ID_REEMPLAZA); dar de baja marca VIGENTE = 0 y no inserta nada.
   El saldo se calcula solo con los vigentes.

   A diferencia de las aplicaciones, la identidad NO es la fecha: dos
   suscripciones el mismo dia a la misma cuenta son dos hechos distintos. Por eso
   la cadena de versiones va por ID_REEMPLAZA y no por (cuenta, fecha).

   El importe es SIEMPRE POSITIVO y el signo lo pone TIPO (SUSCRIPCION suma,
   RESCATE resta), por el mismo motivo que el tablero no tiene columna de signo:
   "un rescate negativo" no significa nada.

   MONEDA se copia de la cuenta al guardar, como hace RO_T_CASHFLOW_SALDOS_DETALLE:
   asi corregir la moneda de una cuenta no reescribe lo que significan sus
   movimientos viejos. En PHP, la moneda de un fondo con movimientos no se deja
   cambiar.

   NO SE REGISTRA CONTRAPARTIDA BANCARIA. Un rescate saca plata del fondo y nada
   mas: lo que entra al banco se va a ver en el saldo bancario, que en breve lo
   trae la API. Registrar la contrapartida aca seria adelantar un dato que otro
   circuito ya va a medir.

   ----------------------------------------------------------------------------
   4. LOS FONDOS DE COBERTURA SON LAS CUENTAS

   Hasta ahora el fondo del que descontaba cada aplicacion era una constante del
   codigo: Cobertura::ORIGENES ('INVERSIONES', 'SUSCRIPCION', 'DOLARES') y el
   registro declaraba a que fondo pertenecia cada stock ('origen_cobertura').
   Con cuentas dadas de alta por el usuario eso ya no puede ser una lista fija:
   CADA CUENTA DE FONDO ES UN FONDO, y su clave es 'CTA_' + ID de la cuenta
   (Fondos::claveFondo()).

   Por eso este script REESCRIBE RO_T_CASHFLOW_COBERTURA_APLIC.ORIGEN en las
   aplicaciones que ya estan: 'INVERSIONES' pasa a la clave de la cuenta de
   inversion migrada y 'DOLARES' a la de la cuenta comitente. Es un cambio de
   clave y no de dato -la aplicacion sigue saliendo del mismo fondo-, y se hace
   sobre TODAS las filas, vigentes y pisadas, para que el historial siga
   leyendose. Es la unica escritura sobre esa tabla y va en la misma transaccion
   que el alta de la cuenta: sin la cuenta no hay clave a la que mover.

   'SUSCRIPCION' no tiene cuenta equivalente (no era un fondo con stock, era una
   etiqueta). Al escribir esto no hay ninguna aplicacion con ese origen; si
   apareciera alguna, el script avisa y la deja como esta para que se reasigne a
   mano desde el tablero.

   El DEFAULT ('INVERSIONES') de ORIGEN se saca: nombraria un fondo que ya no
   existe. Cobertura::guardar() manda siempre el origen.

   ----------------------------------------------------------------------------
   5. LA MIGRACION DE DATOS

   La ULTIMA carga vigente de cada foto pasa a ser el saldo inicial de la cuenta
   equivalente, con su fecha:

       RO_T_CASHFLOW_SALDO_INVERSIONES  -> cuenta 'Inversiones', ARS, INVERSION
       RO_T_CASHFLOW_DOLARES_COMITENTE  -> cuenta 'Cuenta comitente', USD, COMITENTE

   "La ultima" es la de mayor FECHA entre las vigentes, y a igual fecha la de
   mayor ID: es exactamente lo que OtrosIngresosProvider tomaba como stock, asi
   que el dia que se corre el tablero muestra lo mismo. Verificado contra la base
   el 18/09/2026: inversiones 3.529.962,37 del 14/09 (una sola carga), y en
   dolares la vigente mas nueva es 1,00 USD del 18/09 (la de 71.000 del 16/09
   sigue vigente pero es anterior). Ese 1 USD es lo que el tablero ya mostraba;
   si es una carga de prueba, se corrige el saldo inicial desde Parametros.

   Si la tabla de origen no existe o no tiene ninguna carga vigente, la cuenta
   NO se crea: no hay nada que migrar y un fondo en cero inventado seria un
   dato. Se avisa.

   Los nombres son los que ve el usuario y se pueden cambiar desde Parametros.
   Cada bloque de migracion se guarda por la CLASE: si ya existe una cuenta de
   esa clase -por una corrida anterior o porque alguien la dio de alta a mano-
   no se migra nada, y se dice.

   ----------------------------------------------------------------------------
   6. LAS FILAS DE STOCK DEL TABLERO SE REAPUNTAN

   STOCK_INVERSIONES y STOCK_DOLARES_COMITENTE siguen siendo las mismas filas
   -"cuanto hay invertido" no cambio de significado, cambio de donde se lee- y
   pasan de OtrosIngresosProvider a FondosProvider (FONDO_INVERSION y
   FONDO_COMITENTE). Es el mismo UPDATE que hizo sql/cashflow_dolares_comitente.sql
   cuando esa fila cambio de serie. Reapuntar y no dar de baja + crear evita que
   las dos filas puedan quedar activas a la vez, cosa que el validador no
   detecta entre proveedores distintos.

   Para volver atras: apuntarlas de nuevo a SALDO_INVERSIONES / DOLARES_COMITENTE
   desde Parametros -> Cashflow. Los proveedores viejos siguen declarados, con
   la marca de retirados.

   ES REEJECUTABLE: cada bloque pregunta antes de escribir.
   NO BORRA NADA.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'U') IS NULL
BEGIN
    RAISERROR('Falta RO_T_CASHFLOW_SALDOS_CUENTA. Corre primero sql/cashflow_saldos.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. CLASE en el catalogo de cuentas
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'CLASE') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_CUENTA
        ADD CLASE VARCHAR(20) NOT NULL
            CONSTRAINT DF_CF_SAL_CTA_CLASE DEFAULT ('CTA_CORRIENTE');

    PRINT 'Columna CLASE agregada: las cuentas existentes quedan como CTA_CORRIENTE.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CASHFLOW_SALDOS_CUENTA_CLASE')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_CUENTA
        ADD CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CUENTA_CLASE
            CHECK (CLASE IN ('CTA_CORRIENTE', 'CAJA_AHORRO', 'INVERSION', 'COMITENTE'));
END
GO

/* ----------------------------------------------------------------------------
   2. El saldo inicial del fondo, con su fecha. NULL en las cuentas a la vista.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'SALDO_INICIAL') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_CUENTA
        ADD SALDO_INICIAL       DECIMAL(19,4) NULL,
            FECHA_SALDO_INICIAL DATE          NULL;
END
GO

/* Un saldo inicial sin fecha no se puede ubicar en la cuenta corriente, y una
   fecha sin saldo no dice nada. Van los dos o ninguno. */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CASHFLOW_SALDOS_CUENTA_SALDO_INI')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_SALDOS_CUENTA
        ADD CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_CUENTA_SALDO_INI
            CHECK ((SALDO_INICIAL IS NULL AND FECHA_SALDO_INICIAL IS NULL)
                OR (SALDO_INICIAL IS NOT NULL AND FECHA_SALDO_INICIAL IS NOT NULL));
END
GO

/* ----------------------------------------------------------------------------
   3. RO_T_CASHFLOW_SALDOS_FONDO_MOV: la cuenta corriente de cada fondo
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_FONDO_MOV', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDOS_FONDO_MOV (
        ID           INT IDENTITY(1,1) NOT NULL,
        ID_CUENTA    INT            NOT NULL,
        FECHA        DATE           NOT NULL,
        TIPO         VARCHAR(12)    NOT NULL,
        IMPORTE      DECIMAL(19,4)  NOT NULL,
        MONEDA       CHAR(3)        NOT NULL,
        OBSERVACION  VARCHAR(200)   NULL,
        VIGENTE      BIT            NOT NULL
            CONSTRAINT DF_CF_SAL_FMOV_VIGENTE DEFAULT (1),
        /* El movimiento al que esta version reemplaza. Es la cadena del
           historial: NULL en un alta, el ID del anterior en una edicion. */
        ID_REEMPLAZA INT            NULL,
        USUARIO      VARCHAR(50)    NULL,
        FECHA_ALTA   DATETIME       NOT NULL
            CONSTRAINT DF_CF_SAL_FMOV_ALTA DEFAULT (GETDATE()),
        /* Cuando se dio de baja o se piso. Sin esto, un movimiento corregido a
           los cinco minutos y uno que estuvo vigente un mes se ven iguales. */
        FECHA_BAJA   DATETIME       NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_SALDOS_FONDO_MOV PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_SALDOS_FONDO_MOV_CUENTA
            FOREIGN KEY (ID_CUENTA) REFERENCES dbo.RO_T_CASHFLOW_SALDOS_CUENTA (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_SALDOS_FONDO_MOV_REEMPLAZA
            FOREIGN KEY (ID_REEMPLAZA) REFERENCES dbo.RO_T_CASHFLOW_SALDOS_FONDO_MOV (ID),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_FONDO_MOV_TIPO
            CHECK (TIPO IN ('SUSCRIPCION', 'RESCATE')),
        /* El signo lo pone TIPO. Un importe negativo daria vuelta el
           significado del tipo sin que nada lo dijera; el cero no es un
           movimiento. Lo rechaza tambien Fondos::validarImporte(). */
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_FONDO_MOV_IMPORTE
            CHECK (IMPORTE > 0),
        CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_FONDO_MOV_MONEDA
            CHECK (MONEDA IN ('ARS', 'USD'))
    );

    /* La consulta de cada carga del tablero y de la pestana es "los vigentes
       de esta cuenta hasta esta fecha". */
    CREATE NONCLUSTERED INDEX IX_RO_T_CASHFLOW_SALDOS_FONDO_MOV_VIGENTE
        ON dbo.RO_T_CASHFLOW_SALDOS_FONDO_MOV (ID_CUENTA, VIGENTE, FECHA)
        INCLUDE (TIPO, IMPORTE);

    PRINT 'Tabla RO_T_CASHFLOW_SALDOS_FONDO_MOV creada.';
END
GO

/* ----------------------------------------------------------------------------
   4. Migracion del saldo de inversiones (pesos)

   La ultima carga vigente pasa a ser el saldo inicial de una cuenta nueva de
   clase INVERSION. Se guarda por la clase: si ya hay una cuenta INVERSION, no
   se migra nada.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_SALDOS_CUENTA WHERE CLASE = 'INVERSION')
BEGIN
    IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDO_INVERSIONES', 'U') IS NULL
    BEGIN
        PRINT 'AVISO: no existe RO_T_CASHFLOW_SALDO_INVERSIONES, no hay saldo de inversiones que migrar. Da de alta la cuenta desde Parametros -> Saldos.';
    END
    ELSE
    BEGIN
        DECLARE @saldoInv DECIMAL(19,4);
        DECLARE @fechaInv DATE;

        SELECT TOP 1 @saldoInv = IMPORTE_ARS, @fechaInv = FECHA
        FROM dbo.RO_T_CASHFLOW_SALDO_INVERSIONES
        WHERE VIGENTE = 1
        ORDER BY FECHA DESC, ID DESC;

        IF @fechaInv IS NULL
        BEGIN
            PRINT 'AVISO: RO_T_CASHFLOW_SALDO_INVERSIONES no tiene ninguna carga vigente. No se crea la cuenta de inversion.';
        END
        ELSE
        BEGIN
            SET XACT_ABORT ON;
            BEGIN TRANSACTION;

            DECLARE @idInv INT;

            INSERT INTO dbo.RO_T_CASHFLOW_SALDOS_CUENTA
                (TIPO, CLASE, NOMBRE, MONEDA, ORIGEN_DATO, SALDO_INICIAL, FECHA_SALDO_INICIAL,
                 ORDEN, ACTIVO, FECHA_UPDATE, USUARIO)
            VALUES
                ('OTRO', 'INVERSION', 'Inversiones', 'ARS', 'MANUAL', @saldoInv, @fechaInv,
                 (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM dbo.RO_T_CASHFLOW_SALDOS_CUENTA),
                 1, GETDATE(), NULL);

            SET @idInv = SCOPE_IDENTITY();

            /* Las aplicaciones que salian del fondo 'INVERSIONES' ahora salen
               de esta cuenta. Todas las filas, tambien las pisadas: el
               historial tiene que seguir nombrando un fondo que existe. */
            IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'U') IS NOT NULL
            BEGIN
                DECLARE @movidasInv INT;

                UPDATE dbo.RO_T_CASHFLOW_COBERTURA_APLIC
                SET ORIGEN = 'CTA_' + CAST(@idInv AS VARCHAR(10))
                WHERE ORIGEN = 'INVERSIONES';

                SET @movidasInv = @@ROWCOUNT;

                PRINT 'Aplicaciones de cobertura movidas de INVERSIONES a CTA_'
                    + CAST(@idInv AS VARCHAR(10)) + ': ' + CAST(@movidasInv AS VARCHAR(10)) + '.';
            END

            COMMIT TRANSACTION;

            PRINT 'Cuenta "Inversiones" (INVERSION, ARS) creada con saldo inicial '
                + CAST(@saldoInv AS VARCHAR(30)) + ' al ' + CONVERT(VARCHAR(10), @fechaInv, 103) + '.';
        END
    END
END
ELSE
BEGIN
    PRINT 'Ya hay una cuenta de clase INVERSION: no se migra el saldo de inversiones.';
END
GO

/* ----------------------------------------------------------------------------
   5. Migracion de los dolares de la cuenta comitente (dolares)

   Mismo criterio. El saldo inicial va EN DOLARES: la cuenta es en USD y la
   valuacion a pesos la sigue haciendo el proveedor, con la ultima cotizacion
   oficial a la fecha y a la punta vendedora, como hasta ahora.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_SALDOS_CUENTA WHERE CLASE = 'COMITENTE')
BEGIN
    IF OBJECT_ID('dbo.RO_T_CASHFLOW_DOLARES_COMITENTE', 'U') IS NULL
    BEGIN
        PRINT 'AVISO: no existe RO_T_CASHFLOW_DOLARES_COMITENTE, no hay dolares que migrar. Da de alta la cuenta desde Parametros -> Saldos.';
    END
    ELSE
    BEGIN
        DECLARE @saldoDol DECIMAL(19,4);
        DECLARE @fechaDol DATE;

        SELECT TOP 1 @saldoDol = IMPORTE_USD, @fechaDol = FECHA
        FROM dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
        WHERE VIGENTE = 1
        ORDER BY FECHA DESC, ID DESC;

        IF @fechaDol IS NULL
        BEGIN
            PRINT 'AVISO: RO_T_CASHFLOW_DOLARES_COMITENTE no tiene ninguna carga vigente. No se crea la cuenta comitente.';
        END
        ELSE
        BEGIN
            SET XACT_ABORT ON;
            BEGIN TRANSACTION;

            DECLARE @idDol INT;

            INSERT INTO dbo.RO_T_CASHFLOW_SALDOS_CUENTA
                (TIPO, CLASE, NOMBRE, MONEDA, ORIGEN_DATO, SALDO_INICIAL, FECHA_SALDO_INICIAL,
                 ORDEN, ACTIVO, FECHA_UPDATE, USUARIO)
            VALUES
                ('OTRO', 'COMITENTE', 'Cuenta comitente', 'USD', 'MANUAL', @saldoDol, @fechaDol,
                 (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM dbo.RO_T_CASHFLOW_SALDOS_CUENTA),
                 1, GETDATE(), NULL);

            SET @idDol = SCOPE_IDENTITY();

            IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'U') IS NOT NULL
            BEGIN
                DECLARE @movidasDol INT;

                UPDATE dbo.RO_T_CASHFLOW_COBERTURA_APLIC
                SET ORIGEN = 'CTA_' + CAST(@idDol AS VARCHAR(10))
                WHERE ORIGEN = 'DOLARES';

                SET @movidasDol = @@ROWCOUNT;

                PRINT 'Aplicaciones de cobertura movidas de DOLARES a CTA_'
                    + CAST(@idDol AS VARCHAR(10)) + ': ' + CAST(@movidasDol AS VARCHAR(10)) + '.';
            END

            COMMIT TRANSACTION;

            PRINT 'Cuenta "Cuenta comitente" (COMITENTE, USD) creada con saldo inicial USD '
                + CAST(@saldoDol AS VARCHAR(30)) + ' al ' + CONVERT(VARCHAR(10), @fechaDol, 103) + '.';
        END
    END
END
ELSE
BEGIN
    PRINT 'Ya hay una cuenta de clase COMITENTE: no se migran los dolares.';
END
GO

/* ----------------------------------------------------------------------------
   6. El origen de una aplicacion ya no tiene default: nombraria un fondo que
      no existe. Y control de lo que quedo sin fondo.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'U') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE name = 'DF_RO_T_CF_COBAP_ORIGEN')
    BEGIN
        ALTER TABLE dbo.RO_T_CASHFLOW_COBERTURA_APLIC
            DROP CONSTRAINT DF_RO_T_CF_COBAP_ORIGEN;
    END

    IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_COBERTURA_APLIC
               WHERE VIGENTE = 1 AND ORIGEN NOT LIKE 'CTA[_]%')
    BEGIN
        PRINT 'AVISO: hay aplicaciones de cobertura vigentes cuyo origen no es una cuenta de fondo (por ejemplo SUSCRIPCION, o un fondo que no se pudo migrar). Se muestran en el tablero pero no descuentan de ninguna cuenta: reasignalas desde el tablero.';
    END
END
GO

/* ----------------------------------------------------------------------------
   7. Las filas de stock del tablero leen de las cuentas
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NOT NULL
BEGIN
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ORIGEN_PROVIDER = 'FONDO_INVERSION', ORIGEN_SERIE = 'STOCK', FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'STOCK_INVERSIONES' AND ORIGEN_PROVIDER = 'SALDO_INVERSIONES';

    IF @@ROWCOUNT > 0 PRINT 'STOCK_INVERSIONES ahora lee de FONDO_INVERSION.';

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ORIGEN_PROVIDER = 'FONDO_COMITENTE', ORIGEN_SERIE = 'STOCK', FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'STOCK_DOLARES_COMITENTE' AND ORIGEN_PROVIDER = 'DOLARES_COMITENTE';

    IF @@ROWCOUNT > 0 PRINT 'STOCK_DOLARES_COMITENTE ahora lee de FONDO_COMITENTE.';

    /* Si alguna fila ACTIVA sigue leyendo de los proveedores retirados, el
       tablero la muestra igual -los proveedores siguen sirviendo sus fotos-
       pero avisa que el dato ya no se mantiene. Se dice aca tambien. */
    IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
               WHERE ACTIVO = 1 AND ORIGEN_PROVIDER IN ('SALDO_INVERSIONES', 'DOLARES_COMITENTE'))
    BEGIN
        PRINT 'AVISO: hay filas activas del tablero que siguen leyendo de Otros Ingresos, que esta retirado. Apuntalas a FONDO_INVERSION / FONDO_COMITENTE desde Parametros -> Cashflow.';
    END
END
GO

PRINT 'Cuentas de inversion y comitente listas.';
GO
