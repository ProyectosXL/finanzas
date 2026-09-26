/* ============================================================================
   MODULO CASHFLOW - LA FILA "PAGOS CON TARJETAS Y OTROS" DEL TABLERO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ULTIMO de la serie de tarjetas, DESPUES de sql/cashflow_tarjetas.sql
            y sql/cashflow_tarjetas_facturas.sql. No es una dependencia tecnica
            -la fila se crea igual- pero una fila activa apuntada a un proveedor
            cuyas tablas no existen muestra un cero con un aviso, y conviene que
            el cero dure lo menos posible.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   UNA SOLA FILA, en la seccion COSTOS_INDIRECTOS:

     TARJETAS_PAGOS   'Pagos con Tarjetas y Otros'   EGRESO   TARJETAS / TOTAL

   Nada mas. No crea secciones, no toca ninguna fila existente y no toca la
   logica de grupos.

   ----------------------------------------------------------------------------
   UNA FILA Y NO CUATRO, Y ES UNA DECISION

   El proveedor TARJETAS sirve cinco series: SUPERVISORAS, CORPORATIVAS, SOCIOS,
   TOTAL y CORPORATIVAS_EXCLUIDAS. La fila usa TOTAL y las otras cuatro quedan
   declaradas y sin usar.

   No estan de mas: el dia que se quiera partir la fila en sus tres partes, o
   mostrar aparte lo excluido, es agregar filas desde Parametros -> Cashflow y
   apuntarlas a esas series. Sin tocar codigo y sin tocar este script. Eso es
   exactamente lo que el diseno del modulo promete.

   EL TOTAL Y SUS PARTES NO PUEDEN CONVIVIR. El registro declara
   'componentes' => ['TOTAL' => ['SUPERVISORAS', 'CORPORATIVAS', 'SOCIOS']], asi
   que si alguien activa una fila con SUPERVISORAS al lado de esta, el validador
   de Parametros -> Cashflow lo rechaza: el mismo peso entraria dos veces. Para
   partir la fila hay que INHABILITAR esta y activar las tres.

   CORPORATIVAS_EXCLUIDAS NO es componente del TOTAL, y por eso SI puede convivir
   con esta fila: es informativa y su importe no esta en el total, justamente
   porque se decidio que no entre.

   ----------------------------------------------------------------------------
   POR QUE ORDEN 45 Y NO 50

   La seccion tiene hoy Haberes 10, Alquileres 20, Llaves y Renovaciones 30,
   Impuestos 40 y el subtotal Total Costos Indirectos 50. La fila nueva va ANTES
   del subtotal, y 45 la mete ahi sin tocar el orden de ninguna otra fila.

   Es el mismo recurso que uso sql/cashflow_compras_proyectadas.sql, que metio
   sus dos filas en 15 y 25 para dejarlas pegadas a su parte real.

   El orden no queda en 45 para siempre: el editor de Parametros RENUMERA desde
   cero con (indice + 1) * 10 en cada guardado, asi que la primera vez que
   alguien guarde la estructura esta fila pasa a 50 y el subtotal a 60. No es un
   problema -el alcance de un subtotal es por seccion, no posicional- y es lo que
   hace que el orden se repare solo.

   ----------------------------------------------------------------------------
   ENTRA ACTIVA

   A diferencia de las filas que se crean desde la pantalla -que entran
   inhabilitadas para que un alta no pueda romper un tablero que estaba bien-,
   esta entra ACTIVA: su proveedor ya existe y esta construido, asi que no puede
   invalidar nada. Es el mismo criterio de las filas que siembra
   sql/cashflow_estructura.sql.

   El dia que se corre, el tablero SE MUEVE: aparecen los gastos con tarjeta de
   las supervisoras, las facturas de tarjeta corporativa y lo que se haya cargado
   de socios. Cuanto se mueve depende de lo que este cargado; con las tablas
   recien creadas y sin ninguna tarjeta, la fila arranca mostrando solo las
   facturas corporativas SIN VINCULAR, que no entran al flujo, asi que arranca en
   CERO y el proveedor avisa por que.

   ----------------------------------------------------------------------------
   OJO CON EL DOBLE CONTEO CONTRA PROVEEDORES LOCALES

   Las facturas de tarjeta corporativa TAMBIEN estan en el universo de
   Proveedores Locales, en su serie PAGOS_FUERA_CRONOGRAMA -'TARJETA CORP' no es
   una forma del cronograma-.

   CON LA CONFIGURACION DE HOY NO HAY DOBLE CONTEO: la fila de Cuentas a Pagar
   Locales usa la serie PAGOS, que deja esas facturas afuera. Verificado contra la
   base el 26/09/2026.

   PERO si alguien reapunta esa fila a PAGOS_TODO o a PAGOS_FUERA_CRONOGRAMA, o
   activa una fila nueva contra cualquiera de las dos, esas facturas se cuentan
   DOS VECES: una alla y una aca. El validador no lo puede ver, porque son dos
   proveedores distintos y el solapamiento es de datos, no de series. El control
   de abajo lo verifica e imprime el resultado. Ver README-pagos-tarjetas.md y
   README-proveedores-locales.md.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   El tablero queda EXACTAMENTE como hoy: ningun importe cambia y no falta nada,
   porque hasta hoy esta plata no estaba en el cuadro. La pestana Financiero ->
   Pagos con Tarjetas y Otros funciona igual -se lee, se carga y se calcula todo-
   y avisa que lo que muestra todavia no llega al tablero, que es el unico
   sintoma.

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
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'TARJETAS_PAGOS')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA,
         ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('TARJETAS_PAGOS', 'Pagos con Tarjetas y Otros', 'COSTOS_INDIRECTOS', 'EGRESO', 1,
         'TARJETAS', 'TOTAL', 45, 1);

    PRINT 'Creada la fila TARJETAS_PAGOS en COSTOS_INDIRECTOS, orden 45, apuntada a '
        + 'TARJETAS / TOTAL.';
END
ELSE
BEGIN
    /* NO SE PISA NADA. Si la fila ya existe, puede haber sido reordenada,
       renombrada o reapuntada desde Parametros -> Cashflow, y este script no
       tiene por que saber mejor que la persona que lo hizo. Solo se imprime como
       quedo. */
    PRINT 'La fila TARJETAS_PAGOS ya existe: no se toca. Como esta hoy:';

    SELECT CODIGO, NOMBRE, SECCION, TIPO, COMPUTA,
           ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO
    FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE CODIGO = 'TARJETAS_PAGOS';
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

/* 2b. Ninguna otra fila activa apuntando al mismo proveedor. El validador de
       Parametros -> Cashflow tambien lo rechaza; esto lo dice desde la consola,
       que es donde se ve el resultado de haber corrido el script. */
DECLARE @otras INT = (
    SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'TARJETAS' AND ACTIVO = 1 AND CODIGO <> 'TARJETAS_PAGOS');

IF @otras > 0
BEGIN
    PRINT 'ATENCION: hay ' + CAST(@otras AS VARCHAR(10)) + ' otra(s) fila(s) activas leyendo '
        + 'del proveedor TARJETAS. Si alguna usa SUPERVISORAS, CORPORATIVAS o SOCIOS junto a '
        + 'esta, que usa TOTAL, el mismo importe se cuenta DOS VECES. Se listan abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE, ORDEN
    FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'TARJETAS' AND ACTIVO = 1 AND CODIGO <> 'TARJETAS_PAGOS'
    ORDER BY ORDEN;
END
ELSE
BEGIN
    PRINT 'El proveedor TARJETAS alimenta una sola fila activa: sin doble conteo interno.';
END
GO

/* 2c. EL CONTROL QUE EL VALIDADOR NO PUEDE HACER: que ninguna fila activa de
       Proveedores Locales traiga las facturas de tarjeta corporativa, que ahora
       entran por aca.

       No es un solapamiento de series -son dos proveedores distintos- sino de
       datos: las mismas facturas de Tango. Por eso el validador de estructura no
       lo ve y este control existe. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1
             AND ORIGEN_SERIE IN ('PAGOS_TODO', 'PAGOS_FUERA_CRONOGRAMA'))
BEGIN
    PRINT 'ATENCION: DOBLE CONTEO. Hay una fila activa de Proveedores Locales leyendo '
        + 'PAGOS_TODO o PAGOS_FUERA_CRONOGRAMA. Las dos series INCLUYEN las facturas de '
        + 'tarjeta corporativa, que desde este script tambien entran por Pagos con Tarjetas y '
        + 'Otros: el mismo peso se cuenta dos veces y el cuadro cierra igual, asi que nada lo '
        + 'delata. Se listan abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE, ORDEN
    FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1
      AND ORIGEN_SERIE IN ('PAGOS_TODO', 'PAGOS_FUERA_CRONOGRAMA')
    ORDER BY ORDEN;
END
ELSE
BEGIN
    PRINT 'Proveedores Locales no trae las facturas de tarjeta corporativa (su fila no usa '
        + 'PAGOS_TODO ni PAGOS_FUERA_CRONOGRAMA): sin doble conteo entre las dos pestanas.';
END
GO

PRINT 'Fila del tablero lista.';
GO
