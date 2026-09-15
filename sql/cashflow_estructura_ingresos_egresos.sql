/* ============================================================================
   MODULO CASHFLOW - INGRESOS Y EGRESOS COMO BLOQUES
   Reagrupa las secciones bajo dos madres y da de baja Ajustes
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ejecutar DESPUES de sql/cashflow_estructura_disponibilidades.sql
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   Hasta ahora las secciones eran todas hermanas y no habia forma de leer el
   cuadro como "esto entra" / "esto sale": habia cinco subtotales y ninguno
   contestaba cuanto entra en total ni cuanto sale en total.

   Queda asi:

       Ingresos            (madre, DERIVADO)
         Disponibilidades     saldo en bancos + todas las cobranzas del dia
         Ventas               cobranza sobre ventas estimadas, por canal
         -> Total Ingresos

       Egresos             (madre, DERIVADO)
         Costo de Mercaderia
         Costos Directos
         Costos Indirectos
         -> Total Egresos

       Resultados
         Flujo Neto (sin cobertura) = Ingresos - Egresos
         Saldo Final

   NO SE INVENTA NINGUN LENGUAJE DE FORMULAS. El esquema ya preveia este caso:
   ID_PADRE cuelga una seccion de otra y un SUBTOTAL abarca su seccion y todas
   sus hijas en cascada (CashflowEstructura::descendientes()). El cambio es
   reparentar cinco secciones y agregar dos filas SUBTOTAL. El motor no se toca.

   LA SECCION MADRE VA DESPUES DE SUS HIJAS, Y ES A PROPOSITO
   El arbol (ID_PADRE) define el ALCANCE del subtotal; el ORDEN define DONDE SE
   DIBUJA. Un "Total Ingresos" tiene que caer abajo del bloque que totaliza, asi
   que la seccion madre lleva un ORDEN mayor que el de sus hijas. Las dos cosas
   son independientes y tienen que serlo: si el orden mandara sobre el alcance,
   no se podria poner un total abajo de lo que suma.

   POR QUE SE REUSA LA SECCION 'INGRESOS' EN VEZ DE CREAR UNA NUEVA
   Ya existe, inhabilitada desde cashflow_estructura_disponibilidades.sql, y
   vuelve a significar exactamente lo mismo que significaba: el bloque de lo que
   entra. Crear un codigo nuevo dejaria dos secciones llamadas Ingresos en el
   editor -una viva y una muerta- y la muerta no se podria explicar. La fila
   SUB_INGRESOS ('Total Ingresos') tambien existe inhabilitada y se reactiva por
   el mismo motivo. EGRESOS y SUB_EGRESOS si son nuevas: nunca existieron.

   AJUSTES SE VA ENTERA, POR BAJA LOGICA
   La seccion y sus tres filas pasan a ACTIVO = 0. NUNCA UN DELETE: es la regla
   del modulo, y aca importa el doble porque la decision es provisoria -mas
   adelante se evalua volver a incluirlas-. Reactivarlas tiene que ser poner el
   bit en 1 desde Parametros, no volver a cargar la configuracion.

   LA FILA 'VENTAS' SIGUE SIENDO INFORMATIVA y sigue inhabilitada, como la dejo
   el script de disponibilidades. Es venta, no es caja: la caja son las cuatro
   filas de cobranza por canal. Si alguien la reactiva desde Parametros, entra
   con COMPUTA = 0 y el tablero la dibuja con el icono de "no entra en ninguna
   suma"; ver conceptoHtml() en Js/Cashflow.js.

   ES REEJECUTABLE: todo va dentro de un IF que pregunta si la seccion EGRESOS
   ya existe, asi que una segunda corrida no hace nada y no pisa ninguna edicion
   posterior del usuario.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'EGRESOS')
BEGIN
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;

    /* ---- 1. Las dos secciones madre ------------------------------------ */
    /* ROL = 'DERIVADO': no aportan flujo propio, solo contienen la fila
       calculada que totaliza a sus hijas. El ROL no participa de ningun
       calculo -quien decide como suma una fila es su TIPO-, pero describe la
       seccion en el editor y en los avisos del validador. */
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_SECCION
        (CODIGO, NOMBRE, ROL, ID_PADRE, ORDEN, ACTIVO)
    VALUES ('EGRESOS', 'Egresos', 'DERIVADO', NULL, 75, 1);

    /* INGRESOS vuelve a la vida con el rol nuevo. ORDEN 35: despues de
       Disponibilidades (10) y Ventas (30), antes de Costo de Mercaderia (50). */
    UPDATE dbo.RO_T_CASHFLOW_CONF_SECCION
    SET NOMBRE = 'Ingresos',
        ROL = 'DERIVADO',
        ID_PADRE = NULL,
        ORDEN = 35,
        ACTIVO = 1,
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'INGRESOS';

    /* ---- 2. Reparentar ------------------------------------------------- */
    UPDATE dbo.RO_T_CASHFLOW_CONF_SECCION
    SET ID_PADRE = 'INGRESOS', FECHA_UPDATE = GETDATE()
    WHERE CODIGO IN ('DISPONIBILIDADES', 'VENTAS');

    UPDATE dbo.RO_T_CASHFLOW_CONF_SECCION
    SET ID_PADRE = 'EGRESOS', FECHA_UPDATE = GETDATE()
    WHERE CODIGO IN ('COSTO_MERCADERIA', 'COSTOS_DIRECTOS', 'COSTOS_INDIRECTOS');

    /* ---- 3. Los dos totales -------------------------------------------- */
    /* SUB_INGRESOS ya existe, inhabilitada y colgada de VENTAS por el script
       anterior. Vuelve a su sección y a su nombre.

       No hay riesgo de doble conteo con SUB_DISPONIBLE y SUB_INGRESOS_VENTA,
       que quedan DENTRO de su alcance: un SUBTOTAL suma filas de movimiento y
       de saldo, y un subtotal no es ninguna de las dos cosas. Ver
       Cashflow::sumarMovimientos() y la prueba que lo fija en
       tests/test_estructura.php. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET NOMBRE = 'Total Ingresos',
        SECCION = 'INGRESOS',
        TIPO = 'SUBTOTAL',
        COMPUTA = 0,
        ORIGEN_PROVIDER = NULL,
        ORIGEN_SERIE = NULL,
        ORDEN = 10,
        ACTIVO = 1,
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'SUB_INGRESOS';

    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES ('SUB_EGRESOS', 'Total Egresos', 'EGRESOS', 'SUBTOTAL', 0,
            NULL, NULL, 10, 1);

    /* ---- 4. Ajustes se va ---------------------------------------------- */
    /* Las filas van primero: una fila activa en una seccion inhabilitada es un
       error de configuracion que el validador reporta, y no tiene sentido
       dejarlo aunque sea por una sentencia. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 0, FECHA_UPDATE = GETDATE()
    WHERE SECCION = 'AJUSTES';

    UPDATE dbo.RO_T_CASHFLOW_CONF_SECCION
    SET ACTIVO = 0,
        NOMBRE = 'Ajustes (fuera del cuadro; se evalua mas adelante)',
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'AJUSTES';

    COMMIT TRANSACTION;

    PRINT 'Estructura reagrupada en Ingresos y Egresos. Ajustes inhabilitada.';
END
ELSE
BEGIN
    PRINT 'La seccion EGRESOS ya existe: no se hizo nada.';
END
GO

/* ----------------------------------------------------------------------------
   EL ROTULO DE LA FILA DE RESULTADO

   "Flujo Neto (sin cobertura)" es un cambio de dato, no de codigo: el nombre de
   una fila vive en NOMBRE y se edita desde Parametros. Va aca igual para que
   una base nueva quede con el rotulo correcto sin que nadie tenga que
   acordarse, y corre siempre -no esta dentro del IF- porque es idempotente: si
   ya dice eso, el UPDATE no cambia nada.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET NOMBRE = 'Flujo Neto (sin cobertura)', FECHA_UPDATE = GETDATE()
WHERE CODIGO = 'FLUJO_NETO' AND NOMBRE <> 'Flujo Neto (sin cobertura)';
GO
