/* ============================================================================
   MODULO CASHFLOW - LA COBERTURA LA CALCULA EL MOTOR; UNA FILA DE USO POR FONDO
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_cobertura.sql (la seccion y la tabla),
             sql/cashflow_cobertura_por_fondo.sql (la columna MONEDA) y
             sql/cashflow_saldos_cuentas_fondo.sql (las cuentas de fondo y las
             dos filas de stock). Es el 19 de la lista de README-cashflow.md.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   El uso de cobertura deja de cargarse a mano: lo calcula el motor en cada
   carga del tablero, al vuelo, sin persistir nada. Recorre el horizonte en
   orden cronologico arrastrando el saldo y, en cada columna en la que el
   SALDO ACUMULADO quedaria abajo de cero, rescata exactamente lo que falta:
   primero de las cuentas de inversion y, agotadas esas, de las comitente, en
   dolares enteros. Cuando sobra, devuelve. El algoritmo y sus reglas estan en
   cashflow/Class/CoberturaAutomatica.php; este script no tiene nada que ver
   con el calculo. Lo que cambia en la base son tres cosas de estructura.

   1. UNA FILA DE USO POR CLASE DE FONDO

   Habia una sola fila, "Uso de Inversiones", apuntada a COBERTURA/APLICACION,
   que traia todo lo aplicado sin importar de que fondo. Con el motor vendiendo
   dolares cuando las inversiones no alcanzan, hace falta ver cada cosa en su
   fila: cuanto sale de las inversiones y cuanto de la comitente. Por eso el
   proveedor COBERTURA abre la serie en dos, USO_INVERSION y USO_COMITENTE, y
   el cuadro lleva una fila por cada una, en el orden en que se consumen. Y en
   Resultados aparece el acumulado SIN cobertura (ver el bloque 2b):

     Resultados
       10  Flujo Neto (sin cobertura)
       15  Flujo Neto Acumulado (sin cobertura)   SALDO_FINAL   <- nueva
     Cobertura
       10  Inversiones disponibles      STOCK    FONDO_INVERSION / STOCK
       15  Dolares en cuenta comitente  STOCK    FONDO_COMITENTE / STOCK
       20  Uso de Inversiones           USO      COBERTURA / USO_INVERSION
       25  Uso de Dolares comitente     USO      COBERTURA / USO_COMITENTE
       30  Flujo Neto (con cobertura)
       40  Flujo Neto Acumulado (con cobertura)   SALDO_FINAL

   La fila que ya existe SE REAPUNTA, no se da de baja y se crea otra: es la
   misma fila -"cuanto se usa de las inversiones" no cambio de significado,
   cambio lo que trae- y reapuntar evita que las dos puedan quedar activas a
   la vez, que es lo que hizo sql/cashflow_saldos_cuentas_fondo.sql con las de
   stock. La de la comitente es nueva y se crea si no esta.

   APLICACION queda declarada en el registro para poder volver atras desde
   Parametros -> Cashflow, y el registro la relaciona con las otras dos en
   'componentes': el validador no deja activar el total y una parte a la vez,
   porque lo cargado a mano se contaria dos veces.

   2. LA CLAVE DE UNA APLICACION MANUAL PASA A SER FECHA + FONDO

   RO_T_CASHFLOW_COBERTURA_APLIC admitia UNA aplicacion vigente por fecha: la
   fila del tablero era una sola. Con dos fondos aplicables el mismo dia, el
   mismo dia puede llevar una carga desde cada uno, y cada una se pisa y se
   borra por separado. El PHP ya lo hace asi (Cobertura::guardar() y borrar()
   trabajan por FECHA + ORIGEN); este script lo fija en la base con un INDICE
   UNICO FILTRADO por VIGENTE = 1, para que la regla no dependa solo del
   codigo. Filtrado, porque las pisadas y las dadas de baja tienen que poder
   repetir la clave: son el historial.

   Antes de crearlo se controla que no haya dos vigentes con la misma clave.
   Si las hay -no deberia: la clave anterior era mas estricta-, se avisa y NO
   se crea el indice, para que el script no reviente a mitad de camino; se
   resuelven a mano y se vuelve a correr.

   3. UNA CARGA MANUAL NO PUEDE SUPERAR EL FONDO

   Eso no es de la base: lo valida Cobertura::validarDisponible() al guardar,
   contra el saldo del fondo a esa fecha, y rechaza con un mensaje que dice
   cuanto hay. Se menciona aca porque cambia lo que el tablero acepta: antes
   se avisaba y se dejaba pasar. Las aplicaciones YA cargadas no se tocan
   -este script no borra ni corrige importes-; si alguna supera el fondo, el
   tablero lo avisa y el motor no suma encima.

   ----------------------------------------------------------------------------
   NADA DE ESTO CAMBIA UN NUMERO EL DIA QUE SE CORRE, salvo que hoy hubiera
   columnas con Saldo Final en rojo: ahi el motor empieza a rescatar y esas
   columnas dejan de estar en rojo (o quedan con el faltante, si ni con todo
   alcanza). Verificado contra la base el 19/09/2026: no habia ninguna columna
   negativa, las dos filas de stock dan lo mismo antes y despues, y la unica
   aplicacion manual vigente (4.000.000 el 18/09 desde CTA_13) sigue en su
   columna.

   ES REEJECUTABLE: cada bloque pregunta antes de escribir.
   NO BORRA NADA.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'COBERTURA')
BEGIN
    RAISERROR('Falta la seccion COBERTURA. Corre primero sql/cashflow_cobertura.sql.', 16, 1);
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_COBERTURA_APLIC. Corre primero sql/cashflow_cobertura.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La fila de uso que ya existe pasa a traer solo las inversiones.

   Se busca por lo que la fila ES -tipo USO_COBERTURA apuntada a APLICACION- y
   no por su codigo: si alguien la creo a mano con otro codigo, es la misma
   fila igual. Si hay mas de una activa asi, el validador ya se queja del
   origen repetido; se reapuntan todas y se avisa.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET ORIGEN_SERIE = 'USO_INVERSION', FECHA_UPDATE = GETDATE()
WHERE TIPO = 'USO_COBERTURA'
  AND ORIGEN_PROVIDER = 'COBERTURA'
  AND ORIGEN_SERIE = 'APLICACION';

IF @@ROWCOUNT > 0
    PRINT 'La fila de uso de cobertura ahora lee de COBERTURA / USO_INVERSION.';
ELSE
    PRINT 'Ninguna fila de uso apuntaba a APLICACION: no se reapunto nada.';
GO

/* ----------------------------------------------------------------------------
   2. La fila de uso de la comitente.

   ORDEN 25: despues de "Uso de Inversiones" (20) y antes del Flujo Neto con
   cobertura (30). Es el orden en que se consumen los fondos, y ademas tiene
   que quedar ENTRE los dos flujos netos: el alcance de un FLUJO_NETO es
   posicional, y una fila de uso puesta abajo del "con cobertura" no entraria
   en el. La seccion se toma de la fila de uso existente, por si la movieron.

   COMPUTA = 1 como la otra: la plata se mueve de verdad y entra al arrastre.
   Lo que no hace es sumar en el indicador de Ingresos (ver Cashflow::kpiDe()).
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
               WHERE ORIGEN_PROVIDER = 'COBERTURA' AND ORIGEN_SERIE = 'USO_COMITENTE')
BEGIN
    DECLARE @seccion VARCHAR(50);

    SELECT TOP 1 @seccion = SECCION
    FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE TIPO = 'USO_COBERTURA' AND ACTIVO = 1
    ORDER BY ORDEN;

    IF @seccion IS NULL SET @seccion = 'COBERTURA';

    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('USO_DOLARES_COMITENTE', 'Uso de Dólares comitente', @seccion, 'USO_COBERTURA', 1,
         'COBERTURA', 'USO_COMITENTE', 25, 1);

    PRINT 'Fila "Uso de Dolares comitente" creada en la seccion ' + @seccion + '.';
END
ELSE
BEGIN
    PRINT 'La fila de uso de la comitente ya existe: no se hizo nada.';
END
GO

/* ----------------------------------------------------------------------------
   2b. El acumulado SIN cobertura, debajo del Flujo Neto (sin cobertura).

   Leyendo de arriba hacia abajo, el cuadro decia "flujo del dia" y recien
   despues de la seccion Cobertura "posicion acumulada". Con el motor
   rescatando solo, el numero que explica POR QUE rescato -el acumulado en
   rojo antes de cubrir- no estaba en ninguna fila. Ahora hay dos filas de
   saldo: esta, en Resultados, es la posicion sin cobertura; la del final es
   la posicion ya cubierta.

   Es un SALDO_FINAL comun. Desde esta etapa SALDO_FINAL arrastra SOLO lo que
   tiene por encima (Cashflow::resolverDerivadas()), asi que puesta arriba de
   las filas de uso no las ve. La del final no cambia: arrastra todo.

   Se cuelga de la fila 'Flujo Neto (sin cobertura)' -misma seccion, orden
   siguiente- para que quede pegada a ella este donde este. Si esa fila no
   existe con su codigo de siempre, se avisa y no se crea nada: una fila de
   saldo suelta en cualquier seccion diria un numero que nadie pidio.

   Y la fila de saldo que ya existe, si todavia se llama exactamente
   'Flujo Neto Acumulado', pasa a decir '(con cobertura)': con dos filas del
   mismo nombre no se sabria cual es cual. Si alguien ya la renombro, se
   respeta.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'SALDO_ACUM_SIN_COB')
BEGIN
    DECLARE @secFlujo VARCHAR(50), @ordFlujo INT;

    SELECT @secFlujo = SECCION, @ordFlujo = ORDEN
    FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE CODIGO = 'FLUJO_NETO' AND TIPO = 'FLUJO_NETO';

    IF @secFlujo IS NULL
    BEGIN
        PRINT 'AVISO: no existe la fila FLUJO_NETO (Flujo Neto sin cobertura), asi que no se creo el acumulado sin cobertura. Crealo desde Parametros -> Cashflow: tipo SALDO_FINAL, en la seccion del Flujo Neto sin cobertura, debajo de el.';
    END
    ELSE
    BEGIN
        INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
            (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
        VALUES
            ('SALDO_ACUM_SIN_COB', 'Flujo Neto Acumulado (sin cobertura)', @secFlujo, 'SALDO_FINAL', 0,
             NULL, NULL, @ordFlujo + 5, 1);

        PRINT 'Fila "Flujo Neto Acumulado (sin cobertura)" creada en la seccion ' + @secFlujo + '.';
    END
END
ELSE
BEGIN
    PRINT 'El acumulado sin cobertura ya existe: no se hizo nada.';
END
GO

UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET NOMBRE = 'Flujo Neto Acumulado (con cobertura)', FECHA_UPDATE = GETDATE()
WHERE CODIGO = 'SALDO_FINAL' AND NOMBRE = 'Flujo Neto Acumulado';

IF @@ROWCOUNT > 0 PRINT 'La fila de saldo del final ahora se llama "Flujo Neto Acumulado (con cobertura)".';
GO

/* ----------------------------------------------------------------------------
   3. Una aplicacion vigente por FECHA + FONDO.
   ---------------------------------------------------------------------------- */
IF EXISTS (SELECT FECHA, ORIGEN
           FROM dbo.RO_T_CASHFLOW_COBERTURA_APLIC
           WHERE VIGENTE = 1
           GROUP BY FECHA, ORIGEN
           HAVING COUNT(*) > 1)
BEGIN
    PRINT 'AVISO: hay mas de una aplicacion de cobertura VIGENTE para la misma fecha y el mismo fondo. Dejá una sola (VIGENTE = 0 en las otras, con FECHA_BAJA) y volvé a correr este script: el indice unico no se creo.';
END
ELSE IF NOT EXISTS (SELECT 1 FROM sys.indexes
                    WHERE name = 'UX_RO_T_CF_COBAP_VIGENTE_FECHA_ORIGEN'
                      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COBAP_VIGENTE_FECHA_ORIGEN
        ON dbo.RO_T_CASHFLOW_COBERTURA_APLIC (FECHA, ORIGEN)
        WHERE VIGENTE = 1;

    PRINT 'Indice unico (FECHA, ORIGEN) sobre las aplicaciones vigentes creado.';
END
ELSE
BEGIN
    PRINT 'El indice unico por fecha y fondo ya existe.';
END
GO

/* ----------------------------------------------------------------------------
   4. Controles. Ninguno bloquea: dicen donde mirar.
   ---------------------------------------------------------------------------- */

/* Si la fila de stock de la comitente no esta activa, la fila de uso nueva
   muestra solo lo cargado a mano: el motor no rescata de un fondo cuyo saldo
   no ve. Es el caso de una base donde no se corrio
   sql/cashflow_dolares_comitente_cobertura.sql. */
IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
               WHERE ACTIVO = 1 AND TIPO = 'STOCK_COBERTURA'
                 AND ORIGEN_PROVIDER = 'FONDO_COMITENTE')
BEGIN
    PRINT 'AVISO: no hay una fila de stock activa apuntada a FONDO_COMITENTE, asi que el motor no puede rescatar de la comitente. Corre sql/cashflow_dolares_comitente_cobertura.sql y sql/cashflow_saldos_cuentas_fondo.sql, o revisa Parametros -> Cashflow.';
END
GO

/* El total y una parte activas a la vez: lo rechaza el validador, pero el
   aviso dice donde mirar. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ACTIVO = 1 AND ORIGEN_PROVIDER = 'COBERTURA' AND ORIGEN_SERIE = 'APLICACION')
   AND EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
               WHERE ACTIVO = 1 AND ORIGEN_PROVIDER = 'COBERTURA'
                 AND ORIGEN_SERIE IN ('USO_INVERSION', 'USO_COMITENTE'))
BEGIN
    RAISERROR('ATENCION: hay una fila de uso con el total (APLICACION) y otra con una parte activas a la vez: lo cargado a mano se cuenta dos veces. Deja el total o las partes, no los dos.', 16, 1);
END
GO

/* Las aplicaciones vigentes que superan el saldo A HOY de su fondo. No se
   tocan -son datos-, pero desde ahora el tablero no acepta cargar asi, y el
   motor no suma encima de un fondo sobregirado. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'SALDO_INICIAL') IS NOT NULL
BEGIN
    IF EXISTS (
        SELECT a.ORIGEN
        FROM dbo.RO_T_CASHFLOW_COBERTURA_APLIC a
        JOIN dbo.RO_T_CASHFLOW_SALDOS_CUENTA c
          ON a.ORIGEN = 'CTA_' + CAST(c.ID AS VARCHAR(10))
        WHERE a.VIGENTE = 1
        GROUP BY a.ORIGEN, c.SALDO_INICIAL
        HAVING SUM(a.IMPORTE) > ISNULL(c.SALDO_INICIAL, 0)
    )
    BEGIN
        PRINT 'AVISO: hay fondos con mas cobertura manual vigente que su saldo inicial. El tablero lo avisa por fondo; si son cargas viejas, dalas de baja desde el tablero: el motor va a cubrir solo lo que haga falta.';
    END
END
GO

PRINT 'Cobertura automatica lista: una fila de uso por fondo y clave fecha + fondo.';
GO
