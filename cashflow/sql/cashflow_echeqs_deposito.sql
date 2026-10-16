/* ============================================================================
   MODULO CASHFLOW - ECHEQS: EL DEPOSITO DE CHEQUES DE CARTERA
   Crea el juego de alicuotas de los cheques y el plazo de acreditacion
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_ECHEQ_
   Orden   : despues de sql/cashflow_auditoria_usuario.sql (la tabla nace con
             su esquema de auditoria). Para que los depositados tengan donde
             mostrarse hace falta ademas la fila del tablero:
             sql/cashflow_estructura_echeqs_depositados.sql. La marca "ya
             acredito" es aparte: sql/cashflow_echeqs_acreditado.sql.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Tesoreria deposita los cheques de cartera en el banco y registra el
   deposito en Tango en el mismo momento, con una boleta de deposito (BDE): el
   cheque pasa a ESTADO = 'A' y sale de cartera. Desde ahi la plata no entra en
   la fecha del cheque: entra a las 48 hs habiles de la boleta, y entra NETA,
   porque el banco retiene IIBB y SICREB sobre el deposito.

   El cashflow lee esos depositos de Tango y los proyecta en la fila "Echeqs
   depositados", el dia que acreditan y por el neto. Este script crea lo que
   hace falta para calcular las dos cosas: el plazo y las alicuotas. Ver
   README-echeqs.md.

   ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_ECHEQ_DEPOSITO, EN DESUSO

   Este script la creaba para un circuito que ya no existe: el boton
   "Depositar" del cashflow, que guardaba el deposito con su fecha, su tasa y
   su neto. Tesoreria nunca lo uso -registra el deposito en Tango- y las filas
   que tiene son pruebas.

   YA NO SE CREA: una instalacion nueva no tiene por que tener una tabla que
   nadie lee. DONDE YA EXISTE NO SE TOCA: no hay bajas fisicas, y nada del
   modulo la lee ni la escribe (lo fija tests/test_echeqs_deposito.php). Por
   eso tampoco hay un DROP aca.

   Que el script se corrio lo dice la tabla de alicuotas
   (Echeqs::alicuotasCreadas()), no esta.
   ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_ECHEQ_ALICUOTA

   Las retenciones del banco sobre el deposito de cheques. Es el MISMO mecanismo
   que RO_T_CASHFLOW_COBEL_ALICUOTA -concepto libre, vigencias, la suma de las
   vigentes a la fecha de acreditacion- y la regla vive una sola vez, en
   Class/Retenciones.php.

   ES UNA TABLA PROPIA Y NO FILAS EN LA DE COB. ELECTRONICOS. Aquella cuelga de
   una procesadora, y compartirla exigiria una procesadora "Cheques" que
   apareceria en el alta de movimientos y en el ABM de procesadoras. Con dos
   tablas, un cambio en la tasa de Payway no puede mover el neto de un cheque
   ni al reves, y eso no depende de que nadie se acuerde de filtrar.

   NO HAY UNIQUE (CONCEPTO, VIGENCIA_DESDE) A PROPOSITO, igual que en Cob.
   Electronicos: corregir dos veces el mismo porcentaje el mismo dia es lo que
   pasa cuando alguien se equivoca al tipearlo, y gana la insertada despues
   (desempate por ID).

   LA TASA Y EL NETO NO SE GUARDAN EN NINGUN LADO. Se calculan al leer, con las
   alicuotas vigentes a la fecha de acreditacion de cada cheque: cargar o dar
   de baja una alicuota cambia solo el neto de los que todavia no acreditaron.

   NO SIEMBRA NINGUNA ALICUOTA. Los porcentajes del banco los carga Tesoreria
   en Parametros -> Cheques. Mientras no haya ninguna vigente, un cheque
   depositado queda sin neto: no se proyecta y la pestana lo avisa como
   critico. Un neto igual al bruto informaria de mas sin decirlo.
   ----------------------------------------------------------------------------
   3. EL PLAZO DE ACREDITACION

   'echeqs_dias_acreditacion_deposito' = 2 en RO_T_CASHFLOW_PARAMETROS, modulo
   PRECHEQUEADO -la sub-pestana que ahora se llama Cheques; el codigo no cambio
   para no perder los permisos ya asignados-. Son DIAS HABILES BANCARIOS
   despues de la boleta de deposito: con 2, lunes -> miercoles y viernes ->
   martes. Como el neto, la fecha se calcula al leer: cambiar el plazo mueve
   tambien los cheques que todavia no acreditaron.

   Si el parametro ya existe NO se toca su VALOR: alguien pudo haberlo editado.
   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO, los cheques depositados en Tango empiezan a tener
   neto y a proyectarse -si ya hay alicuotas cargadas- en "Echeqs depositados".
   Antes de eso se listan en la pestana sin proyectarse, con un aviso critico
   que nombra este script.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   2. LAS ALICUOTAS DE LOS CHEQUES
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA (
        ID             INT IDENTITY(1,1) NOT NULL,
        /* Libre: hoy IIBB y SICREB. Una retencion nueva es una fila, no un
           CHECK ni un cambio de codigo. */
        CONCEPTO       VARCHAR(30)   NOT NULL,
        /* Fraccion: 2,5% es 0,025. */
        ALICUOTA       DECIMAL(9,6)  NOT NULL,
        VIGENCIA_DESDE DATE          NOT NULL,
        ACTIVO         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQALI_ACTIVO DEFAULT (1),

        USUARIO_ALTA   VARCHAR(50)   NULL,
        FECHA_ALTA     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQALI_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF  VARCHAR(50)   NULL,
        FECHA_MODIF    DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQALI_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA   VARCHAR(50)   NULL,
        FECHA_BAJA     DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ECHEQ_ALICUOTA PRIMARY KEY CLUSTERED (ID),
        /* Una alicuota sola nunca puede llegar a 1. La suma la valida PHP. */
        CONSTRAINT CK_RO_T_CF_ECHEQALI_RANGO CHECK (ALICUOTA >= 0 AND ALICUOTA < 1)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_ECHEQALI_VIGENCIA
        ON dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA (CONCEPTO, VIGENCIA_DESDE DESC, ID DESC)
        INCLUDE (ALICUOTA, ACTIVO);

    PRINT 'Creada dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA. No se siembra ninguna alicuota: se cargan en Parametros -> Cheques.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   3. EL PLAZO DE ACREDITACION

   Grupo DEPOSITO y no GENERAL: la sub-pestana no tiene seccion 'generales' -la
   dibujaria con el editor generico, que guarda sin validar- y el plazo se
   edita en su propia seccion, que valida que sea un entero de 1 a 30.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
               WHERE CLAVE = 'echeqs_dias_acreditacion_deposito')
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
        INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
        VALUES ('echeqs_dias_acreditacion_deposito', '2', 'INT',
                'Dias habiles bancarios entre el deposito de un cheque de cartera y su acreditacion',
                'DEPOSITO');
    ELSE
        EXEC sp_executesql N'
            INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO)
            VALUES (''echeqs_dias_acreditacion_deposito'', ''2'', ''INT'',
                    ''Dias habiles bancarios entre el deposito de un cheque de cartera y su acreditacion'',
                    ''DEPOSITO'', ''PRECHEQUEADO'');';

    PRINT 'Parametro echeqs_dias_acreditacion_deposito sembrado en 2 dias habiles.';
END
ELSE
BEGIN
    PRINT 'El parametro echeqs_dias_acreditacion_deposito ya existe: no se toca su valor.';
END
GO

/* ----------------------------------------------------------------------------
   4. Control: el plazo tiene que ser un entero de 1 a 30.

   Se pudo haber editado por fuera de la pantalla. Con un valor invalido los
   depositados no se proyectan y la pestana dice por que; esto lo avisa al
   correr.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
    WHERE CLAVE = 'echeqs_dias_acreditacion_deposito'
      AND (TRY_CAST(VALOR AS INT) IS NULL OR TRY_CAST(VALOR AS INT) NOT BETWEEN 1 AND 30)
)
BEGIN
    RAISERROR('ATENCION: echeqs_dias_acreditacion_deposito no es un entero de 1 a 30. Corregilo en Parametros -> Cheques: mientras tanto los depositados no se proyectan.', 16, 1);
END
GO

PRINT 'Alicuotas y plazo del deposito de cheques listos.';
GO
