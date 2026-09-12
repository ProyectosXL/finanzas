/* ============================================================================
   MODULO CASHFLOW - OTROS INGRESOS: SALDO DE INVERSIONES
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_
   Orden   : se puede correr en cualquier momento. Sin el, la pestana avisa que
             falta la tabla y la fila del tablero va en cero.
   ----------------------------------------------------------------------------
   QUE ES ESTO

   El saldo de las inversiones, cargado a mano desde
   Otros Ingresos -> Saldo de Inversiones.

   Es el mismo circuito que Dolares Cuenta Comitente -formulario minimo, sin
   baja fisica, historial por fecha- y a proposito: ver
   cashflow_dolares_comitente.sql y README-otros-ingresos.md. La unica
   diferencia esta abajo y es la moneda.

   ES UN INGRESO, NO UNA DISPONIBILIDAD. El importe entra al flujo en la fecha
   que se le carga; no es un saldo de apertura y no arrastra.

   ----------------------------------------------------------------------------
   SE GUARDA EN PESOS. ES UNA DECISION, NO UN DESCUIDO.

   El campo es IMPORTE_ARS y NO HAY CONVERSION: lo que se carga es lo que entra
   al tablero.

   Dolares Cuenta Comitente hace lo contrario -guarda USD y el proveedor los
   valua con el oficial del BCRA de cada mes- justamente para no congelar la
   valuacion: son dolares que estan en la cuenta, y el dia que cambia el tipo de
   cambio cambia cuanto valen en pesos. Aca el saldo se informa en pesos, asi que
   no hay nada que valuar y una conversion seria inventar una moneda de origen
   que el dato no tiene.

   PARA DARLO VUELTA, SI ALGUN DIA EL SALDO SE INFORMA EN DOLARES:

     1. Renombrar la columna a IMPORTE_USD (o agregarla y migrar).
     2. Hacer que OtrosIngresosProvider::saldoInversiones() convierta con el
        mapa mensual de Cotizacion, exactamente como hace dolaresComitente().
     3. Poner 'moneda' => 'USD' en la entrada SALDO_INVERSIONES de
        CashflowRegistry.
     4. Cambiar el rotulo de la pantalla y el del formulario.

   Los cuatro pasos estan en cuatro archivos y ninguno adivina la moneda del
   otro: es lo que hace que la decision sea reversible y no un supuesto
   desparramado.
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

IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDO_INVERSIONES', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_SALDO_INVERSIONES (
        ID          INT IDENTITY(1,1) NOT NULL,
        FECHA       DATE           NOT NULL,
        IMPORTE_ARS DECIMAL(18,2)  NOT NULL,
        VIGENTE     BIT            NOT NULL
            CONSTRAINT DF_RO_T_CF_SALDINV_VIGENTE DEFAULT (1),
        USUARIO     VARCHAR(50)    NULL,
        FECHA_ALTA  DATETIME       NOT NULL
            CONSTRAINT DF_RO_T_CF_SALDINV_ALTA DEFAULT (GETDATE()),
        CONSTRAINT PK_RO_T_CASHFLOW_SALDO_INVERSIONES PRIMARY KEY CLUSTERED (ID)
    );

    /* El indice va por (VIGENTE, FECHA) y no por FECHA sola: la consulta que
       corre en cada carga del tablero es "los vigentes, por fecha". */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_SALDINV_VIGENTE
        ON dbo.RO_T_CASHFLOW_SALDO_INVERSIONES (VIGENTE, FECHA)
        INCLUDE (IMPORTE_ARS);

    /* Para abrir el historial de una fecha puntual. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_SALDINV_FECHA
        ON dbo.RO_T_CASHFLOW_SALDO_INVERSIONES (FECHA, ID);
END
GO

/* ----------------------------------------------------------------------------
   LA FILA DEL TABLERO

   A diferencia de DOLARES_COMITENTE, esta fila NO existia: hay que crearla.
   Va al lado de la de dolares -es el otro concepto de la misma categoria- y en
   la seccion donde haya quedado esa, que depende de si la base corrio
   cashflow_estructura_disponibilidades.sql.

   TOMA LA SECCION Y EL ORDEN DE LA FILA DE DOLARES en vez de escribirlos a
   mano, por el mismo motivo que cashflow_estructura_split_cobranzas_fr.sql:
   escribir 'INGRESOS' en duro dejaria la fila colgando de una seccion
   inhabilitada en una base ya migrada, y la fila no se dibujaria.

   Es un INGRESO: el importe entra al flujo en la fecha que se le carga.

   LA FILA ENTRA ACTIVA, igual que la de dolares. Una fila apuntada a un
   proveedor que existe y a una serie que ofrece no puede invalidar la
   estructura; lo que hace es mostrar cero hasta que alguien cargue, que es
   exactamente lo que tiene que mostrar.

   ES REEJECUTABLE: el insert va dentro de un IF NOT EXISTS por CODIGO.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'SALDO_INVERSIONES')
    BEGIN
        /* La seccion de la fila de dolares, si existe; si no, la de la semilla.
           El orden va pegado al de dolares para que las dos queden juntas. */
        DECLARE @seccion VARCHAR(50);
        DECLARE @orden INT;

        SELECT TOP 1 @seccion = SECCION, @orden = ORDEN + 1
        FROM dbo.RO_T_CASHFLOW_CONF_FILA
        WHERE CODIGO = 'DOLARES_COMITENTE';

        IF @seccion IS NULL
        BEGIN
            SELECT TOP 1 @seccion = CODIGO
            FROM dbo.RO_T_CASHFLOW_CONF_SECCION
            WHERE CODIGO IN ('DISPONIBILIDADES', 'INGRESOS') AND ACTIVO = 1
            ORDER BY CASE CODIGO WHEN 'DISPONIBILIDADES' THEN 1 ELSE 2 END;

            SET @orden = 66;
        END

        IF @seccion IS NOT NULL
        BEGIN
            INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
                (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
            VALUES ('SALDO_INVERSIONES', 'Saldo de Inversiones', @seccion, 'INGRESO', 1,
                    'SALDO_INVERSIONES', 'INGRESO', @orden, 1);
        END
    END
END
GO

PRINT 'Tabla y fila del saldo de inversiones listas.';
GO
