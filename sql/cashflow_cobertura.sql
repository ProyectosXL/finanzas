/* ============================================================================
   MODULO CASHFLOW - SECCION COBERTURA
   Aplicacion del saldo de inversiones para tapar los baches del flujo
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_
   Orden   : ejecutar DESPUES de sql/cashflow_estructura_ingresos_egresos.sql
   ----------------------------------------------------------------------------
   QUE RESUELVE

   El tablero proyecta el saldo dia por dia y en algunas columnas da negativo o
   queda muy justo. La plata para cubrir eso existe -esta invertida-, pero el
   tablero no tenia donde decir CUANDO se la piensa usar, ni mostrar cuanta hay.

   La seccion Cobertura, debajo del Flujo Neto, tiene tres filas y cierra con el
   saldo final:

       Inversiones disponibles    cuanto hay. NO va en ninguna columna de fecha:
                                  es un stock, no un flujo. Se muestra solo en
                                  la columna Total.
       Uso de Inversiones         cuanto se aplica en cada fecha. Se edita desde
                                  el propio tablero y puede ser negativa.
       Flujo Neto (con cobertura) el de arriba mas lo aplicado en esa columna.
       Saldo Final                la posicion proyectada, ya con la cobertura.

   POR QUE NO HACE FALTA NINGUNA REGLA NUEVA EN EL MOTOR

   El alcance de las filas calculadas es POSICIONAL. "Uso de Inversiones" queda
   DEBAJO de "Flujo Neto (sin cobertura)" y ARRIBA de "Flujo Neto (con
   cobertura)", asi que el primero la excluye y el segundo la incluye, sin que
   el motor tenga que saber que existe la cobertura. Es exactamente el caso de
   uso del resultado intermedio que el diseno ya preveia.

   Y SALDO_FINAL SE MUEVE AL FINAL DE ESTA SECCION por el mismo motivo: suma lo
   que tiene por encima, asi que ahi recoge el uso de cobertura. Si se quedara
   en Resultados, mostraria la posicion sin cubrir y contradiria a la fila que
   tiene justo arriba. Resultados queda con el Flujo Neto sin cobertura, que es
   lo que ese bloque contesta.

   EL SALDO DE INVERSIONES DEJA DE SER UN INGRESO

   Su fila en Disponibilidades queda INHABILITADA. Entrar al flujo como ingreso
   en la fecha de su carga decia que ese dia ingresaba plata, y no es cierto: la
   plata ya esta, y lo que hay que decidir es cuando se usa. Esa decision ahora
   se carga aca.

   Esto CONTRADICE lo que decia el encabezado de
   sql/cashflow_saldo_inversiones.sql ("ES UN INGRESO, NO UNA DISPONIBILIDAD");
   ese texto quedo reescrito junto con este cambio, porque una nota que dice lo
   contrario de lo que hace el codigo es peor que no tener nota.

   DOLARES CUENTA COMITENTE NO SE TOCA: sigue siendo un INGRESO. En el Excel
   esta en el bloque del Disponible y no en el de inversiones, y es plata en una
   cuenta, no un fondo invertido.

   ES REEJECUTABLE: cada bloque pregunta antes de escribir.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LOS DOS TIPOS DE FILA NUEVOS

   El CHECK del DDL y CashflowEstructura::TIPOS son la misma lista escrita dos
   veces, y TIENEN QUE CAMBIAR JUNTOS: si el PHP acepta un tipo que la base
   rechaza, el editor de estructura deja elegirlo y despues el guardado
   explota contra la restriccion.

   En SQL Server un CHECK no se modifica: se borra y se vuelve a crear. Va
   dentro de un IF por si alguien ya lo corrio.
   ---------------------------------------------------------------------------- */
IF EXISTS (SELECT 1 FROM sys.check_constraints
            WHERE name = 'CK_RO_T_CASHFLOW_CONF_FILA_TIPO'
              AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA'))
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints
                    WHERE name = 'CK_RO_T_CASHFLOW_CONF_FILA_TIPO'
                      AND definition LIKE '%STOCK_COBERTURA%')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA
        DROP CONSTRAINT CK_RO_T_CASHFLOW_CONF_FILA_TIPO;

    ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA
        ADD CONSTRAINT CK_RO_T_CASHFLOW_CONF_FILA_TIPO
            CHECK (TIPO IN ('SALDO_INICIAL', 'INGRESO', 'EGRESO',
                            'SUBTOTAL', 'FLUJO_NETO', 'SALDO_FINAL',
                            'STOCK_COBERTURA', 'USO_COBERTURA'));

    PRINT 'CHECK de TIPO ampliado con STOCK_COBERTURA y USO_COBERTURA.';
END
GO

/* ----------------------------------------------------------------------------
   2. LA TABLA DE APLICACIONES

   UNA APLICACION VIGENTE POR FECHA. La fila del tablero es una sola, asi que la
   pregunta que contesta esta tabla es "cuanta cobertura se aplica el dia X".
   ORIGEN dice de que fondo sale y es un dato de la aplicacion, no parte de su
   identidad: dos aplicaciones del mismo dia desde dos fondos distintos son una
   sola decision de tesoreria.

   EL IMPORTE PUEDE SER NEGATIVO, y no lleva CHECK que lo impida: un negativo es
   sacar plata de la cuenta y volver a invertirla, que en una columna con saldo
   de sobra es una decision tan real como aplicar cobertura. Lo que si se
   rechaza -en PHP, en Cobertura::validarImporte()- es el cero: cero no es una
   aplicacion de cero pesos, es no tener ninguna, y para eso esta la baja.

   NO HAY BAJA FISICA. Pisar una fecha marca VIGENTE = 0 las cargas anteriores e
   inserta una nueva; borrar marca VIGENTE = 0 y no inserta nada. El historial
   es lo unico que explica por que el saldo proyectado de ayer era otro: con un
   UPDATE, corregir un dedazo y cambiar de plan son indistinguibles despues del
   hecho. Es el mismo criterio de RO_T_CASHFLOW_SALDO_INVERSIONES.

   ES REEJECUTABLE: la tabla se crea solo si no existe.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBERTURA_APLIC (
        ID          INT IDENTITY(1,1) NOT NULL,
        FECHA       DATE           NOT NULL,
        IMPORTE     DECIMAL(18,2)  NOT NULL,
        ORIGEN      VARCHAR(30)    NOT NULL
            CONSTRAINT DF_RO_T_CF_COBAP_ORIGEN  DEFAULT ('INVERSIONES'),
        OBSERVACION VARCHAR(200)   NULL,
        VIGENTE     BIT            NOT NULL
            CONSTRAINT DF_RO_T_CF_COBAP_VIGENTE DEFAULT (1),
        USUARIO     VARCHAR(50)    NULL,
        FECHA_ALTA  DATETIME       NOT NULL
            CONSTRAINT DF_RO_T_CF_COBAP_ALTA    DEFAULT (GETDATE()),
        /* Cuando se dio de baja esta carga. Con FECHA_ALTA sola no se puede
           distinguir una carga que se piso a los cinco minutos de una que
           estuvo vigente tres semanas, y esa diferencia es justamente lo que
           explica un saldo proyectado viejo. */
        FECHA_BAJA  DATETIME       NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COBERTURA_APLIC PRIMARY KEY CLUSTERED (ID)
    );

    /* La consulta que corre en cada carga del tablero es "las vigentes, por
       fecha". Mismo criterio de indice que el saldo de inversiones. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_COBAP_VIGENTE
        ON dbo.RO_T_CASHFLOW_COBERTURA_APLIC (VIGENTE, FECHA)
        INCLUDE (IMPORTE, ORIGEN, OBSERVACION);

    /* Para abrir el historial de una fecha puntual. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_COBAP_FECHA
        ON dbo.RO_T_CASHFLOW_COBERTURA_APLIC (FECHA, ID);

    PRINT 'Tabla de aplicacion de cobertura creada.';
END
GO

/* ----------------------------------------------------------------------------
   3. LA SECCION Y SUS FILAS

   ORDEN 95: despues de Resultados (90), que se queda con el Flujo Neto sin
   cobertura.

   EL NOMBRE 'Cobertura' VA EN NOMBRE Y NO EN EL CODIGO DE NEGOCIO: se puede
   renombrar desde Parametros sin tocar nada. El CODIGO si es estable, porque es
   lo que referencian las filas.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'COBERTURA')
BEGIN
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;

    INSERT INTO dbo.RO_T_CASHFLOW_CONF_SECCION
        (CODIGO, NOMBRE, ROL, ID_PADRE, ORDEN, ACTIVO)
    VALUES ('COBERTURA', 'Cobertura', 'DERIVADO', NULL, 95, 1);

    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        /* El stock. COMPUTA = 0: no entra en ninguna suma, y ademas el motor le
           vacia todas las columnas de fecha. Enlaza a Otros Ingresos -> Saldo de
           Inversiones, que es donde ese numero se carga y se puede auditar. */
        ('STOCK_INVERSIONES', 'Inversiones disponibles', 'COBERTURA', 'STOCK_COBERTURA', 0,
            'SALDO_INVERSIONES', 'STOCK', 10, 1),

        /* La aplicacion. COMPUTA = 1: esta plata SI se mueve y tiene que entrar
           al arrastre del saldo. Lo que no hace es sumar en el indicador de
           Ingresos, porque no es plata que el negocio genere. Ver
           Cashflow::kpiDe(). */
        ('USO_COBERTURA', 'Uso de Inversiones', 'COBERTURA', 'USO_COBERTURA', 1,
            'COBERTURA', 'APLICACION', 20, 1),

        /* El resultado. Es un FLUJO_NETO comun: suma todo lo que tiene por
           encima, que a esta altura del cuadro incluye la fila de uso. No hace
           falta acumular nada ni definir un tipo nuevo. */
        ('FLUJO_NETO_COB', 'Flujo Neto (con cobertura)', 'COBERTURA', 'FLUJO_NETO', 0,
            NULL, NULL, 30, 1);

    /* SALDO_FINAL baja al final de esta seccion. Ver la nota del encabezado:
       su alcance es posicional, asi que aca -y solo aca- recoge el uso de
       cobertura y muestra la posicion realmente proyectada. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET SECCION = 'COBERTURA', ORDEN = 40, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'SALDO_FINAL';

    /* El saldo de inversiones deja de ser un ingreso. Baja logica: reactivarla
       es poner el bit en 1, pero ojo -mostraria el mismo dinero dos veces, y el
       validador lo rechaza porque el registro relaciona las series STOCK e
       INGRESO en 'componentes'. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 0,
        NOMBRE = 'Saldo de Inversiones (pasó a ser stock de cobertura)',
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'SALDO_INVERSIONES';

    COMMIT TRANSACTION;

    PRINT 'Seccion Cobertura creada y Saldo Final movido a su final.';
END
ELSE
BEGIN
    PRINT 'La seccion COBERTURA ya existe: no se hizo nada.';
END
GO
