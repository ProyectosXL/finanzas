/* ============================================================================
   MODULO CASHFLOW - LA FILA "GASTOS LOCALES" DEL TABLERO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_gastos_locales.sql. No es una dependencia
            tecnica -la fila se crea igual y el proveedor usa los valores por
            defecto- pero sin los parametros no se puede cambiar el dia de pago
            ni la lista de cuentas.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   UNA SOLA FILA, en la seccion COSTOS_INDIRECTOS:

     GASTOS_LOCALES   'Gastos Locales'   EGRESO   GASTOS_LOCALES / PAGOS

   Nada mas. No crea secciones ni toca ninguna fila existente.

   ----------------------------------------------------------------------------
   POR QUE ORDEN 47

   La seccion tiene hoy Haberes 10, Alquileres 20, Llaves y Renovaciones 30,
   Impuestos 40, Pagos con Tarjetas y Otros 45 y el subtotal Total Costos
   Indirectos 50. La fila nueva va pegada a Pagos con Tarjetas y Otros -que
   tiene los gastos de las supervisoras, el otro gasto operativo de los
   locales- y antes del subtotal. 47 la mete ahi sin tocar ninguna otra.

   El editor de Parametros -> Cashflow renumera con (indice + 1) * 10 en cada
   guardado, asi que el 47 dura hasta el primer guardado. No es un problema: el
   alcance del subtotal es la seccion, no la posicion.

   ----------------------------------------------------------------------------
   ENTRA ACTIVA, Y EL TABLERO SE MUEVE

   Como las filas que siembra sql/cashflow_estructura.sql: su proveedor ya
   existe, asi que no puede invalidar nada. El dia que se corre aparece un
   egreso nuevo, que hasta hoy no estaba en el cuadro: al 31/10/2026, unos
   $ 9,2 millones en el horizonte (promedio de $ 24.768 por dia).

   ----------------------------------------------------------------------------
   OJO CON EL DOBLE CONTEO CONTRA PROVEEDORES LOCALES

   Lo que un local paga con la caja PUEDE ser una factura de un proveedor que
   tambien esta en Cuentas a Pagar Locales. CON LA CONFIGURACION DE HOY NO SE
   CUENTA DOS VECES: la fila de Proveedores Locales usa la serie PAGOS, que
   trae solo lo que se paga por echeq o transferencia y deja la caja afuera.
   Si alguien la reapunta a PAGOS_TODO o PAGOS_FUERA_CRONOGRAMA, lo pagado con
   caja podria entrar por las dos filas. El control 2b lo verifica.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   El tablero queda EXACTAMENTE como hoy. La pestana Proveedores -> Gastos
   Locales funciona igual: lee, calcula y muestra; lo unico que no pasa es que
   su total llegue al tablero.

   ES REEJECUTABLE: el INSERT va con NOT EXISTS sobre el CODIGO, y la segunda
   corrida no modifica ninguna fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guardas duras: sin la tabla de estructura o sin la seccion no hay donde
      colgar la fila, y crearla en otra seccion seria peor que no crearla.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_CONF_FILA. Corre primero sql/cashflow_estructura.sql.', 16, 1);
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION
               WHERE CODIGO = 'COSTOS_INDIRECTOS' AND ACTIVO = 1)
BEGIN
    RAISERROR('No existe la seccion COSTOS_INDIRECTOS activa. Corre sql/cashflow_estructura.sql y sql/cashflow_estructura_ingresos_egresos.sql.', 16, 1);
END
GO

/* ============================================================================
   1. LA FILA
   ============================================================================ */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'GASTOS_LOCALES')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA,
         ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('GASTOS_LOCALES', 'Gastos Locales', 'COSTOS_INDIRECTOS', 'EGRESO', 1,
         'GASTOS_LOCALES', 'PAGOS', 47, 1);

    PRINT 'Creada la fila GASTOS_LOCALES en COSTOS_INDIRECTOS, orden 47, apuntada a '
        + 'GASTOS_LOCALES / PAGOS.';
END
ELSE
BEGIN
    /* NO SE PISA NADA: si ya existe, puede haber sido reordenada, renombrada
       o reapuntada desde Parametros -> Cashflow. Solo se imprime como esta. */
    PRINT 'La fila GASTOS_LOCALES ya existe: no se toca. Como esta hoy:';

    SELECT CODIGO, NOMBRE, SECCION, TIPO, COMPUTA,
           ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO
    FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE CODIGO = 'GASTOS_LOCALES';
END
GO

/* ============================================================================
   2. CONTROLES: COMO QUEDO
   ============================================================================ */

/* 2a. La seccion entera, para ver que la fila quedo ANTES del subtotal. */
PRINT 'Costos Indirectos, como queda:';
GO

SELECT ORDEN, CODIGO, NOMBRE, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ACTIVO
FROM dbo.RO_T_CASHFLOW_CONF_FILA
WHERE SECCION = 'COSTOS_INDIRECTOS'
ORDER BY ORDEN;
GO

/* 2b. Lo que el validador no puede ver: que Proveedores Locales no traiga
       tambien lo pagado con caja. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1
             AND ORIGEN_SERIE IN ('PAGOS_TODO', 'PAGOS_FUERA_CRONOGRAMA'))
BEGIN
    PRINT 'ATENCION: POSIBLE DOBLE CONTEO. Hay una fila activa de Proveedores Locales leyendo '
        + 'PAGOS_TODO o PAGOS_FUERA_CRONOGRAMA, que incluyen lo que se paga con caja. Lo que '
        + 'los locales pagan con su caja tambien entra por Gastos Locales. Se listan abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE, ORDEN
    FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1
      AND ORIGEN_SERIE IN ('PAGOS_TODO', 'PAGOS_FUERA_CRONOGRAMA')
    ORDER BY ORDEN;
END
ELSE
BEGIN
    PRINT 'Proveedores Locales no trae lo pagado con caja (su fila no usa PAGOS_TODO ni '
        + 'PAGOS_FUERA_CRONOGRAMA): sin doble conteo entre las dos pestanas.';
END
GO
