/* ============================================================================
   MODULO COBRANZAS FR - FECHA DE COBRO MANUAL POR FACTURA
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : puede correrse en cualquier momento
   ----------------------------------------------------------------------------
   QUE RESUELVE

   La fecha probable de cobro de una factura pendiente se calcula como
   FECHA_EMIS + PPP del cliente. El PPP es un promedio, asi que sirve para el
   grueso de la cartera y no sirve cuando alguien ya sabe la fecha de esa
   factura puntual porque la hablo con el franquiciado.

   Esta tabla guarda esa fecha pactada, comprobante por comprobante.

   JERARQUIA: LA FECHA MANUAL MANDA
   Si hay fila aca para el comprobante, esa es la fecha de cobro. Si no, vale
   FECHA_EMIS + PPP. Y la fecha manual RECALCULA los dias -DATEDIFF entre
   emision y cobro-, con lo que cambia el tramo de la escala de descuento y el
   importe neto. No es solo mover el importe de columna.

   LA FECHA MANUAL SOBREVIVE A LA FACTURA
   No se limpia cuando el comprobante sale del listado -se cancela, se paga o
   entra en una propuesta-. Queda guardada y vuelve a aplicar sola si el
   comprobante reaparece. Borrarla automaticamente perderia una decision que
   alguien tomo, y el sintoma seria una fecha que se "desconfigura sola".

   LA CLAVE ES (T_COMP, N_COMP) Y NO INCLUYE AL CLIENTE
   El par tipo + numero identifica al comprobante en Tango; el codigo de
   cliente se guarda al lado para poder buscar y auditar, pero no forma parte
   de la unicidad. Si estuviera en la clave, un mismo comprobante cargado con
   dos codigos de cliente distintos daria dos fechas y ninguna ganaria.

   ----------------------------------------------------------------------------
   ESTA MISMA TABLA SIRVE TAMBIEN A COBRANZAS MAYORISTAS

   El nombre dice FR por donde nacio, no por a quien sirve. No se creo una tabla
   paralela ni se le agrego una columna de origen, y las dos decisiones salen de
   la unicidad de arriba:

     - La clave YA ES el comprobante, y un comprobante pertenece a un solo
       circuito: los de franquicias son COD_CLIENT LIKE 'FR%' y los de
       mayoristas LIKE 'MA%'. No puede ser los dos.

     - Una columna ORIGEN tendria que coincidir siempre con lo que dice el
       codigo de cliente, o sea que seria un dato que se puede contradecir con
       otro. Y si entrara en la unicidad, el mismo comprobante podria tener dos
       fechas manuales distintas, que es exactamente lo que el parrafo de arriba
       dice que no puede pasar.

     - Una tabla paralela duplicaria el circuito entero -leer, guardar, borrar,
       la jerarquia de resolverFechaCobro()- para guardar la misma fila con el
       mismo significado.

   Por eso los metodos de PHP se llaman getFechasManuales(), saveFechaManual() y
   deleteFechaManual(), sin sufijo: el sufijo FR sobrevive solo en el nombre de
   la tabla, donde renombrar cuesta mas de lo que aclara.

   LA DIFERENCIA ENTRE LOS DOS CIRCUITOS NO ESTA ACA, ESTA EN EL IMPORTE. En
   franquicias la fecha manual recalcula los dias, y con los dias cambia el
   tramo de la escala de descuento y el importe neto. En mayoristas NO hay
   escala: el neto es el bruto, asi que lo unico que cambia es en que columna
   del eje cae la plata. Ver Ingresos::getCobranzasMay().

   ES REEJECUTABLE: la tabla se crea solo si no existe.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL (
        ID          INT IDENTITY(1,1) NOT NULL,
        COD_CLIENTE VARCHAR(10)  NOT NULL,
        T_COMP      VARCHAR(10)  NOT NULL,
        N_COMP      VARCHAR(20)  NOT NULL,
        FECHA_COBRO DATE         NOT NULL,
        USUARIO     VARCHAR(50)  NULL,
        FECHA_ALTA  DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_COB_FMANUAL_ALTA DEFAULT (GETDATE()),
        FECHA_MOD   DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL UNIQUE (T_COMP, N_COMP)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_COB_FMANUAL_CLIENTE
        ON dbo.RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL (COD_CLIENTE)
        INCLUDE (T_COMP, N_COMP, FECHA_COBRO);
END
GO

PRINT 'Tabla de fecha de cobro manual lista.';
GO
