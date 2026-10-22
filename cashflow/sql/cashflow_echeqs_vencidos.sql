/* ============================================================================
   MODULO CASHFLOW - ECHEQS: LOS CHEQUES VENCIDOS EN CARTERA Y SU FECHA DE
   ACREDITACION INTERNA
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_ECHEQ_
   Orden   : despues de sql/cashflow_auditoria_usuario.sql (la tabla nace con
             su esquema de auditoria). No depende de ningun otro: sin este
             script, la pestana Echeqs no lee los cheques vencidos, avisa que
             falta correrlo, y todo lo demas -la cartera de hoy en adelante, el
             corrimiento al dia habil, los depositados- anda igual.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   La cartera se leia desde hoy: un cheque que seguia en cartera (ESTADO = 'C')
   despues de su fecha desaparecia del tablero sin aviso, aunque Tesoreria lo
   fuera a depositar en unos dias. Medido el 21/10/2026 sobre 180 dias: 163
   cheques salieron por boleta de deposito DESPUES de su fecha, 15 de ellos con
   4 a 26 dias de atraso. Durante ese atraso la plata no estaba en ninguna fila.

   Ahora se leen los cheques en cartera con fecha hasta N dias corridos hacia
   atras -el parametro de abajo, 30, el plazo legal para presentar un cheque al
   cobro-, y Tesoreria les asigna una FECHA DE ACREDITACION INTERNA: el dia en
   que espera que esa plata entre. Sin fecha, un vencido NO SUMA: nadie dijo
   cuando entra, y ponerlo en una fecha inventada seria informar de mas.

   UN CHEQUE ES VENCIDO cuando su dia habil -la fecha del cheque, corrida al
   habil siguiente si cae un fin de semana o un feriado- es anterior a hoy. Un
   cheque del sabado leido el lunes cae hoy, como uno fechado hoy. Lo decide
   Echeqs::resolverFechaCartera(); ver README-echeqs.md.

   ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION

   La fecha SOLO VIVE EN EL CASHFLOW: no se escribe nada en Tango. Se valida en
   PHP (Echeqs::validarFechaAsignada()): de manana en adelante, un dia habil, y
   hasta el menor entre el fin del horizonte y el ultimo dia en que el cheque
   se sigue mostrando (su fecha + N). La fecha asignada NUNCA SE CORRE SOLA: ya
   se exige habil.

   DEJA DE APLICAR SOLA, sin tocar esta tabla:
     - si Tesoreria lo deposita en Tango, pasa a 'A' y entra en "Echeqs
       depositados" con su propia regla; la fecha queda en el historial;
     - si se rechaza o se anula, sale de la lectura;
     - si sale de la ventana, sale de la lectura;
     - si la fecha llega y el cheque sigue en cartera, no suma y la pestana y
       el tablero piden reasignarla.

   NO HAY BAJAS FISICAS. Reasignar da de baja la vigente e inserta la nueva
   con ID_REEMPLAZA; deshacer marca VIGENTE = 0 con quien y cuando. Es el
   criterio de RO_T_CASHFLOW_ECHEQ_EXCLUIDO y RO_T_CASHFLOW_ECHEQ_ACREDITADO:
   la PK es un ID y no ID_SBA14, porque un cheque puede tener varias filas en
   el tiempo y una sola vigente.

   SIN FK CONTRA dbo.SBA14: es una tabla de Tango, y una FK nuestra haria
   fallar una depuracion de un sistema que no es nuestro. Una fecha huerfana no
   aparece en ninguna lectura: todas parten de la cartera.

   ----------------------------------------------------------------------------
   2. EL PARAMETRO echeqs_dias_vencidos_cartera

   Dias corridos hacia atras desde hoy en que un cheque vencido en cartera se
   sigue mostrando: un entero de 1 a 30, sembrado en 30. Se edita en
   Parametros -> Cheques, que valida. Si ya existe NO se toca su VALOR: alguien
   pudo haberlo editado.

   ----------------------------------------------------------------------------
   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: los vencidos
   aparecen en la pestana sin fecha, y un vencido sin fecha no suma. Lo que
   aparece es un aviso con cuantos son y por cuanto.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LAS FECHAS ASIGNADAS
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION (
        ID                 INT IDENTITY(1,1) NOT NULL,
        /* La PK de dbo.SBA14. N_CHEQUE NO identifica un cheque: se repite entre
           bancos y entre anios. */
        ID_SBA14           INT           NOT NULL,
        FECHA_ACREDITACION DATE          NOT NULL,
        OBSERVACION        VARCHAR(200)  NULL,
        VIGENTE            BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQFAC_VIGENTE DEFAULT (1),
        /* La fecha que esta reemplaza, al reasignar. */
        ID_REEMPLAZA       INT           NULL,

        USUARIO_ALTA       VARCHAR(50)   NULL,
        FECHA_ALTA         DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQFAC_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF      VARCHAR(50)   NULL,
        FECHA_MODIF        DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_ECHEQFAC_MODIF DEFAULT (GETDATE()),
        /* Quien la dio de baja -al deshacer o al reasignar- y cuando. */
        USUARIO_BAJA       VARCHAR(50)   NULL,
        FECHA_BAJA         DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_ECHEQFAC_REEMPLAZA FOREIGN KEY (ID_REEMPLAZA)
            REFERENCES dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION (ID)
    );

    /* La lectura de cada carga de la pestana y del tablero es "las fechas de
       estos cheques", vigentes o no -el tooltip cuenta las anteriores-. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_ECHEQFAC_CHEQUE
        ON dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION (ID_SBA14, VIGENTE);

    PRINT 'Creada dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UNA SOLA FECHA VIGENTE POR CHEQUE.

   PHP ya da de baja la vigente antes de grabar la nueva, en una transaccion,
   pero dos pestanas pueden asignar la misma al mismo tiempo: el indice es lo
   que hace que la segunda falle en la base.

   INDICE UNICO FILTRADO y no constraint: un UNIQUE comun prohibiria tambien
   las filas historicas repetidas, que es lo que esta tabla tiene que guardar.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_RO_T_CF_ECHEQFAC_VIGENTE'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQFAC_VIGENTE
        ON dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION (ID_SBA14)
        WHERE VIGENTE = 1;

    PRINT 'Creado el indice unico de fechas vigentes.';
END
GO

/* ----------------------------------------------------------------------------
   3. LA VENTANA DE LOS VENCIDOS

   Grupo DEPOSITO, como el plazo de acreditacion: la sub-pestana Cheques no
   tiene seccion 'generales' -la dibujaria con el editor generico, que guarda
   sin validar- y la ventana se edita en su propia seccion, que valida que sea
   un entero de 1 a 30.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
               WHERE CLAVE = 'echeqs_dias_vencidos_cartera')
BEGIN
    IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
        INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
        VALUES ('echeqs_dias_vencidos_cartera', '30', 'INT',
                'Dias hacia atras desde la fecha del cheque en que un cheque vencido en cartera se sigue mostrando',
                'DEPOSITO');
    ELSE
        EXEC sp_executesql N'
            INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO)
            VALUES (''echeqs_dias_vencidos_cartera'', ''30'', ''INT'',
                    ''Dias hacia atras desde la fecha del cheque en que un cheque vencido en cartera se sigue mostrando'',
                    ''DEPOSITO'', ''PRECHEQUEADO'');';

    PRINT 'Parametro echeqs_dias_vencidos_cartera sembrado en 30 dias.';
END
ELSE
BEGIN
    PRINT 'El parametro echeqs_dias_vencidos_cartera ya existe: no se toca su valor.';
END
GO

/* ----------------------------------------------------------------------------
   4. Control: la ventana tiene que ser un entero de 1 a 30.

   Se pudo haber editado por fuera de la pantalla. Con un valor invalido los
   vencidos no se leen y la pestana y el tablero lo avisan como critico; esto
   lo avisa al correr.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS
    WHERE CLAVE = 'echeqs_dias_vencidos_cartera'
      AND (TRY_CAST(VALOR AS INT) IS NULL OR TRY_CAST(VALOR AS INT) NOT BETWEEN 1 AND 30)
)
BEGIN
    RAISERROR('ATENCION: echeqs_dias_vencidos_cartera no es un entero de 1 a 30. Corregilo en Parametros -> Cheques: mientras tanto los vencidos en cartera no se leen.', 16, 1);
END
GO

PRINT 'Cheques vencidos en cartera: fechas de acreditacion y ventana listas.';
GO
