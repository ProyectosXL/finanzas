/* ============================================================================
   MODULO CASHFLOW - FILA DE LOS COSTOS DE COBRO DE VENTAS
   Agrega la fila COSTOS_COBRO a la seccion VENTAS
   ----------------------------------------------------------------------------
   Base   : central. Reejecutable.
   Orden  : ejecutar DESPUES de sql/cashflow_estructura_disponibilidades.sql
            (crea la seccion VENTAS y sus filas por canal) y junto con
            sql/cashflow_ventas_mix_nodo.sql, al publicar el codigo de
            feature/ventas-mix-apertura.
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   El mix de cobro de las ventas proyectadas paso a ser un arbol con costo y
   tasa por nodo: la comision del marketplace, la de la procesadora y la tasa
   de las cuotas (ver cashflow/Class/MixCobro.php). Lo que quien cobra se queda
   es plata que no entra, y el tablero tiene que mostrarlo.

   La cobranza de Ventas SIGUE SALIENDO BRUTA -las filas Locales, Franquicias,
   Mayoristas y Ecommerce no cambian de significado- y el costo sale por su
   propia serie, VENTAS.COSTO_COBRO, en negativo. Esta fila es la que la
   muestra. Es exactamente el criterio del neteo de cheques adelantados (ver
   sql/cashflow_estructura_neteo_prechequeado.sql): el costo es informacion
   que el cuadro tiene que mostrar, no una correccion que tenga que esconder.

   POR QUE TIPO = 'INGRESO' CON IMPORTE NEGATIVO, Y NO 'EGRESO'
   Mismo razonamiento que el neteo. No es un pago que sale: es cobranza que no
   va a entrar. Como EGRESO se veria como un pago, y ademas el motor le daria
   signo -1 a un importe que YA viene negativo -lo da vuelta
   VentasProvider::enNegativo()-, con lo cual el costo terminaria SUMANDO.
   Un ingreso negativo resta.

   ES CRITICO QUE LAS SERIES DE COBRANZA SIGAN SIENDO BRUTAS. Si alguien
   restara el costo adentro de COBRANZA -o de las series por canal- teniendo
   esta fila activa, el costo se descontaria DOS VECES y ninguna validacion lo
   detectaria. Esta escrito tambien en el encabezado de
   cashflow/Class/Providers/VentasProvider.php.

   COMPUTA = 1: entra en el subtotal "Total Ventas". Es el punto: el subtotal
   de la seccion tiene que dar la cobranza NETA, la que efectivamente entra.

   EL ORDEN SE CALCULA, NO SE ESCRIBE. La fila va despues de Ecommerce y antes
   del subtotal, y los ordenes de esa seccion no son los de la semilla -en
   central Ecommerce esta en 50 y el subtotal en 60, no en 40 y 50-, porque se
   reordenaron desde Parametros -> Cashflow. Por eso se leen los reales y la
   fila entra en el medio del hueco (hoy, 55). Si no hay hueco, o si una de
   las dos filas no esta, el script lo dice y NO INSERTA: elegir otro lugar
   cambiaria el cuadro sin que nadie lo pida, y el lugar se elige desde
   Parametros.

   NO SE AGRUPA CON LAS FILAS POR CANAL. Se evaluo dibujarla junto con las
   cuatro de cobranza y el neteo en un renglon que se abre (el mecanismo de
   sql/cashflow_estructura_grupos.sql), y se descarto: todas las filas de
   ingreso de la seccion entrarian en el grupo -el neteo esta entre
   Franquicias y Mayoristas y un grupo tiene que ser consecutivo-, asi que el
   renglon cerrado daria exactamente "Total Ventas" y el cuadro mostraria el
   mismo numero dos veces, uno abajo del otro. La fila queda suelta, debajo
   de Ecommerce, y "Total Ventas" es la cobranza neta.

   ES REEJECUTABLE: va con MERGE sobre CODIGO -que es UNIQUE- asi que una
   segunda corrida no duplica la fila. Donde la fila ya existe no pisa NOMBRE
   ni ORDEN: si alguien la movio o la renombro desde Parametros -> Cashflow,
   esa edicion se respeta. Lo unico que reafirma es el ORIGEN, que es lo que
   la ata al proveedor.

   SI NO SE CORRE: el tablero muestra la cobranza de Ventas en bruto, sin el
   costo de cobro, y no avisa -cada serie es correcta por separado-. Mientras
   no haya costos cargados en el mix, da lo mismo.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* La seccion VENTAS la crea cashflow_estructura_disponibilidades.sql. Sin ella
   la fila quedaria huerfana, asi que el script avisa y no hace nada. */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'VENTAS')
BEGIN
    PRINT 'FALTA la seccion VENTAS: corre antes sql/cashflow_estructura_disponibilidades.sql. No se hizo nada.';
END
ELSE IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'COSTOS_COBRO')
BEGIN
    /* Ya existe: solo el origen. NOMBRE y ORDEN pueden haberse editado desde
       la pantalla de estructura y esa edicion vale mas que esta semilla. */
    MERGE dbo.RO_T_CASHFLOW_CONF_FILA AS T
    USING (VALUES ('COSTOS_COBRO', 'VENTAS', 'COSTO_COBRO'))
        AS S (CODIGO, ORIGEN_PROVIDER, ORIGEN_SERIE)
        ON T.CODIGO = S.CODIGO
    WHEN MATCHED AND (ISNULL(T.ORIGEN_PROVIDER, '') <> S.ORIGEN_PROVIDER
                      OR ISNULL(T.ORIGEN_SERIE, '') <> S.ORIGEN_SERIE) THEN
        UPDATE SET ORIGEN_PROVIDER = S.ORIGEN_PROVIDER,
                   ORIGEN_SERIE = S.ORIGEN_SERIE,
                   FECHA_UPDATE = GETDATE();

    PRINT 'La fila COSTOS_COBRO ya existia: se reafirmo su origen y no se toco nada mas.';
END
ELSE
BEGIN
    DECLARE @ecommerce INT, @subtotal INT, @orden INT;

    SELECT @ecommerce = ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA
     WHERE CODIGO = 'VTA_ECOMMERCE' AND SECCION = 'VENTAS';

    SELECT @subtotal = ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA
     WHERE CODIGO = 'SUB_INGRESOS_VENTA' AND SECCION = 'VENTAS';

    IF @ecommerce IS NULL OR @subtotal IS NULL
    BEGIN
        PRINT 'AVISO: no estan las filas VTA_ECOMMERCE y SUB_INGRESOS_VENTA en la seccion VENTAS, '
            + 'asi que no se sabe donde va la fila de costos. No se inserto. Creala desde '
            + 'Parametros -> Cashflow con la serie VENTAS -> COSTO_COBRO, tipo Ingreso y computando.';
    END
    ELSE IF @subtotal - @ecommerce < 2
    BEGIN
        PRINT 'AVISO: no hay hueco entre Ecommerce (ORDEN ' + CAST(@ecommerce AS VARCHAR(10))
            + ') y Total Ventas (ORDEN ' + CAST(@subtotal AS VARCHAR(10)) + '). No se inserto. '
            + 'Abri lugar desde Parametros -> Cashflow y volve a correr este script.';
    END
    ELSE
    BEGIN
        SET @orden = @ecommerce + (@subtotal - @ecommerce) / 2;

        IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
                    WHERE SECCION = 'VENTAS' AND ORDEN = @orden)
        BEGIN
            PRINT 'AVISO: el ORDEN ' + CAST(@orden AS VARCHAR(10)) + ', entre Ecommerce y Total '
                + 'Ventas, ya lo usa otra fila. No se inserto. Abri lugar desde Parametros -> '
                + 'Cashflow y volve a correr este script.';
        END
        ELSE
        BEGIN
            MERGE dbo.RO_T_CASHFLOW_CONF_FILA AS T
            USING (VALUES
                ('COSTOS_COBRO', 'Costos de cobro (comisiones y tasas)', 'VENTAS', 'INGRESO', 1,
                    'VENTAS', 'COSTO_COBRO', @orden)
            ) AS S (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN)
                ON T.CODIGO = S.CODIGO
            WHEN NOT MATCHED BY TARGET THEN
                INSERT (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
                VALUES (S.CODIGO, S.NOMBRE, S.SECCION, S.TIPO, S.COMPUTA,
                        S.ORIGEN_PROVIDER, S.ORIGEN_SERIE, S.ORDEN, 1);

            PRINT 'Fila COSTOS_COBRO creada en la seccion VENTAS con ORDEN '
                + CAST(@orden AS VARCHAR(10)) + ', entre Ecommerce y Total Ventas.';
        END
    END
END
GO
