/* ============================================================================
   MODULO CASHFLOW - ESTRUCTURA SEGUN EL EXCEL ORIGINAL
   Reorganiza la seccion de ingresos en DISPONIBILIDADES + VENTAS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ejecutar DESPUES de sql/cashflow_estructura.sql
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   La semilla original armaba una sola seccion "Ingresos" con todo adentro. El
   Excel original no tiene eso: tiene dos bloques distintos.

   1. DISPONIBILIDADES: el saldo en bancos MAS todo lo que entra por cobranzas
      (echeqs, cobranzas electronicas, franquicias, mayoristas, dolares de la
      cuenta comitente, exportaciones, caja de locales). Su subtotal es la fila
      "Disponible".

      Verificado contra el Excel:
          Disponible(8/9) = SaldoInicial(8/9) + Echeqs + CobElec + CobFranq
                          = 157.226.313 + 59.779.740 + 20.863.853 + 31.863.324
                          = 269.733.230
      Es decir: el subtotal INCLUYE la fila de saldo. Por eso el motor suma las
      filas de saldo inicial dentro del alcance de un subtotal.

   2. VENTAS: la cobranza sobre ventas estimadas, ABIERTA POR CANAL (Locales,
      Franquicias, Mayoristas, Ecommerce). Su subtotal es "Ingresos Venta".

      Y el arrastre cierra entre los dos bloques:
          SaldoInicial(9/9) = Disponible(8/9) + IngresosVenta(8/9) - egresos
                            = 269.733.230 + 70.280.833 = 340.014.063

   FILAS QUE SE INHABILITAN, NO SE BORRAN
   El sistema no tiene bajas. Las filas que este cambio reemplaza quedan
   inhabilitadas y visibles en el editor, para poder reactivarlas:
     - COBROS_VENTAS  (el total) lo reemplazan las cuatro filas por canal. Si
       las cinco estuvieran activas se contaria dos veces lo mismo, y el
       validador lo rechaza.
     - VENTAS (la informativa) no esta en el bloque del Excel.
     - SUB_INGRESOS lo reemplazan los dos subtotales nuevos.

   FILAS QUE VAN A RENDIR CERO
   En el Excel, Dolares Cuenta Comitente, Exportaciones y Caja Locales las tipea
   una persona. Este modulo resuelve el origen de datos unicamente por
   proveedor, asi que hasta que exista el modulo que las alimente se muestran en
   cero y el tablero avisa. Quedan declaradas para que el cuadro tenga la forma
   completa.

   ES REEJECUTABLE: todo el cambio va dentro de un IF que pregunta si la seccion
   DISPONIBILIDADES ya existe, asi que una segunda corrida no hace nada y no
   pisa ninguna edicion posterior del usuario.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'DISPONIBILIDADES')
BEGIN
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;

    /* ---- 1. Secciones nuevas ------------------------------------------- */
    /* ORDEN 5 y 15: quedan antes de las secciones de costos, que estan en 30,
       40, 50 y 60, sin tener que renumerarlas. */
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_SECCION
        (CODIGO, NOMBRE, ROL, ID_PADRE, ORDEN, ACTIVO)
    VALUES
        ('DISPONIBILIDADES', 'Disponibilidades', 'SALDO',      NULL,  5, 1),
        ('VENTAS',           'Ventas',           'MOVIMIENTO', NULL, 15, 1);

    /* ---- 2. Mover las filas que ya existen a Disponibilidades ---------- */
    /* El saldo en bancos deja de ser una seccion propia y pasa a ser la
       primera fila del bloque de disponibilidades. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET SECCION = 'DISPONIBILIDADES',
        NOMBRE = 'Saldo Inicial (bancos)',
        ORDEN = 10,
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'DISPONIBLE';

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET SECCION = 'DISPONIBILIDADES', ORDEN = 20, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'ECHEQS';

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET SECCION = 'DISPONIBILIDADES', ORDEN = 30, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'COB_ELECTRONICOS';

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET SECCION = 'DISPONIBILIDADES', ORDEN = 40, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'COBRANZAS_FR';

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET SECCION = 'DISPONIBILIDADES', ORDEN = 50, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'COBRANZAS_MAY';

    /* ---- 3. Filas nuevas de Disponibilidades --------------------------- */
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('DOLARES_COMITENTE', 'Dolares Cuenta Comitente', 'DISPONIBILIDADES', 'INGRESO', 1,
            'DOLARES_COMITENTE', 'DISPONIBLE', 60, 1),
        ('EXPORTACIONES', 'Exportaciones', 'DISPONIBILIDADES', 'INGRESO', 1,
            'EXPORTACIONES', 'COBRANZA', 70, 1),
        ('CAJA_LOCALES', 'Caja Locales', 'DISPONIBILIDADES', 'INGRESO', 1,
            'CAJA_LOCALES', 'DEPOSITOS', 80, 1),
        /* El subtotal del bloque. Incluye la fila de saldo: es el "Disponible"
           del Excel. */
        ('SUB_DISPONIBLE', 'Disponible', 'DISPONIBILIDADES', 'SUBTOTAL', 0,
            NULL, NULL, 90, 1);

    /* ---- 4. Ventas, abierta por canal ---------------------------------- */
    /* Son la COBRANZA por canal y no la venta: en el Excel estas filas siguen
       el calendario bancario (los fines de semana no tienen columna y el lunes
       concentra el acumulado), que es exactamente el comportamiento de la
       cobranza con corrimiento a dia habil. Si se quisiera ver la venta en su
       lugar, se cambia el origen de cada fila desde Parametros, sin tocar
       codigo: el proveedor expone las dos series por canal. */
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('VTA_LOCALES', 'Locales', 'VENTAS', 'INGRESO', 1,
            'VENTAS', 'COBRANZA_LOCALES', 10, 1),
        ('VTA_FRANQUICIAS', 'Franquicias', 'VENTAS', 'INGRESO', 1,
            'VENTAS', 'COBRANZA_FRANQUICIAS', 20, 1),
        ('VTA_MAYORISTAS', 'Mayoristas', 'VENTAS', 'INGRESO', 1,
            'VENTAS', 'COBRANZA_MAYORISTAS', 30, 1),
        ('VTA_ECOMMERCE', 'Ecommerce', 'VENTAS', 'INGRESO', 1,
            'VENTAS', 'COBRANZA_ECOMMERCE', 40, 1),
        ('SUB_INGRESOS_VENTA', 'Ingresos Venta', 'VENTAS', 'SUBTOTAL', 0,
            NULL, NULL, 50, 1);

    /* ---- 5. Inhabilitar lo que quedo reemplazado ----------------------- */
    /* No se borra nada: quedan visibles en el editor para poder reactivarlas.
       COBROS_VENTAS trae el TOTAL de la cobranza estimada, asi que dejarlo
       activo junto a las cuatro filas por canal contaria dos veces el mismo
       importe. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 0,
        NOMBRE = 'Cobros s/ ventas estimadas (total, reemplazado por canal)',
        SECCION = 'VENTAS',
        ORDEN = 60,
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'COBROS_VENTAS';

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 0,
        NOMBRE = 'Venta proyectada total (informativa)',
        SECCION = 'VENTAS',
        ORDEN = 70,
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'VENTAS';

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 0,
        NOMBRE = 'Total Ingresos (reemplazado por Disponible e Ingresos Venta)',
        SECCION = 'VENTAS',
        ORDEN = 80,
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'SUB_INGRESOS';

    /* Las dos secciones viejas quedan vacias: se inhabilitan. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_SECCION
    SET ACTIVO = 0, NOMBRE = 'Disponible Inicial (reemplazada por Disponibilidades)',
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'DISPONIBLE_INICIAL';

    UPDATE dbo.RO_T_CASHFLOW_CONF_SECCION
    SET ACTIVO = 0, NOMBRE = 'Ingresos (reemplazada por Disponibilidades y Ventas)',
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'INGRESOS';

    COMMIT TRANSACTION;

    PRINT 'Estructura reorganizada en DISPONIBILIDADES + VENTAS.';
END
ELSE
BEGIN
    PRINT 'La seccion DISPONIBILIDADES ya existe: no se hizo nada.';
END
GO
