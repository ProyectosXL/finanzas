/* ============================================================================
   MODULO CASHFLOW - OTROS INGRESOS: DOLARES CUENTA COMITENTE
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_
   Orden   : se puede correr en cualquier momento. Sin el, la pestana avisa que
             falta la tabla y la fila del tablero va en cero.
   ----------------------------------------------------------------------------
   QUE ES ESTO

   Los dolares disponibles en la cuenta comitente. En el Excel original esta
   fila la tipeaba una persona; ahora se carga desde la pantalla
   Otros Ingresos -> Dolares Cuenta Comitente.

   ES UN INGRESO, NO UNA DISPONIBILIDAD. El importe entra al flujo en la fecha
   que se le carga; no es un saldo de apertura ni arrastra.

   SE GUARDAN DOLARES, NO PESOS. La conversion a pesos la hace el proveedor,
   con el oficial del BCRA de Class/Cotizacion.php, igual que ComexProvider con
   los pagos al exterior. Guardar pesos congelaria la valuacion al momento de
   la carga y el dia que cambie el tipo de cambio el tablero seguiria mostrando
   la conversion vieja.

   ----------------------------------------------------------------------------
   EL IMPORTE VIGENTE SE PISA, PERO EL HISTORIAL QUEDA

   Cargar una fecha que ya existe NO hace UPDATE: marca VIGENTE = 0 las
   anteriores de esa fecha e inserta una fila nueva, todo en una transaccion.

   No hay baja fisica, igual que en el resto del modulo. Y el historial no es
   decoracion: es lo unico que explica por que el numero de ayer era otro. Con
   un UPDATE, la correccion de un dedazo y la carga de un dato nuevo son
   indistinguibles despues del hecho.

   El proveedor y la grilla leen UNICAMENTE VIGENTE = 1.

   ES REEJECUTABLE: la tabla se crea solo si no existe.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_DOLARES_COMITENTE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_DOLARES_COMITENTE (
        ID          INT IDENTITY(1,1) NOT NULL,
        FECHA       DATE           NOT NULL,
        IMPORTE_USD DECIMAL(18,2)  NOT NULL,
        VIGENTE     BIT            NOT NULL
            CONSTRAINT DF_RO_T_CF_DOLCOM_VIGENTE DEFAULT (1),
        USUARIO     VARCHAR(50)    NULL,
        FECHA_ALTA  DATETIME       NOT NULL
            CONSTRAINT DF_RO_T_CF_DOLCOM_ALTA DEFAULT (GETDATE()),
        CONSTRAINT PK_RO_T_CASHFLOW_DOLARES_COMITENTE PRIMARY KEY CLUSTERED (ID)
    );

    /* El indice va por (VIGENTE, FECHA) y no por FECHA sola: la consulta que
       corre en cada carga del tablero es "los vigentes, por fecha". */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_DOLCOM_VIGENTE
        ON dbo.RO_T_CASHFLOW_DOLARES_COMITENTE (VIGENTE, FECHA)
        INCLUDE (IMPORTE_USD);

    /* Para abrir el historial de una fecha puntual. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_DOLCOM_FECHA
        ON dbo.RO_T_CASHFLOW_DOLARES_COMITENTE (FECHA, ID);
END
GO

/* ----------------------------------------------------------------------------
   LA FILA DEL TABLERO

   Hasta ahora DOLARES_COMITENTE era un proveedor declarado con
   'disponible' => false: la fila existia y rendia cero. Ahora tiene modulo, y
   con el cambia la SERIE: pasa de 'DISPONIBLE' a 'INGRESO'.

   Ese UPDATE hace falta. El validador de estructura rechaza una fila que
   apunte a una serie que el proveedor no ofrece, asi que sin esto la fila
   quedaria invalida en cuanto se declare el proveedor nuevo.

   Es un INGRESO y no una disponibilidad: el importe entra al flujo en la fecha
   que se le carga y no arrastra.

   ES REEJECUTABLE: el insert va dentro de un IF NOT EXISTS por CODIGO y el
   update pregunta por la serie vieja.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'DOLARES_COMITENTE')
    BEGIN
        UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
        SET ORIGEN_SERIE = 'INGRESO',
            TIPO = 'INGRESO',
            FECHA_UPDATE = GETDATE()
        WHERE CODIGO = 'DOLARES_COMITENTE'
          AND (ORIGEN_SERIE <> 'INGRESO' OR TIPO <> 'INGRESO');
    END
    ELSE
    BEGIN
        /* Instalacion que no corrio cashflow_estructura_disponibilidades.sql:
           la fila no existe y va a la seccion de la semilla. */
        INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
            (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
        SELECT 'DOLARES_COMITENTE', 'Dolares Cuenta Comitente', 'INGRESOS', 'INGRESO', 1,
               'DOLARES_COMITENTE', 'INGRESO', 65, 1
        WHERE EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'INGRESOS');
    END
END
GO

PRINT 'Tabla y fila de dolares en cuenta comitente listas.';
GO
