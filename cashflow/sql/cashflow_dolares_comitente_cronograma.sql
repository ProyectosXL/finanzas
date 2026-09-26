/* ============================================================================
   MODULO CASHFLOW - DOLARES CUENTA COMITENTE: LA FECHA DE CRONOGRAMA
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_dolares_comitente.sql.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   La tabla tenia UNA sola FECHA haciendo dos cosas a la vez:

     - la fecha del DATO   -> con que cotizacion se valuan esos dolares
     - la columna del EJE  -> en que dia del cronograma se muestra el importe

   Y no son la misma pregunta. Se puede saber hoy que va a haber dolares
   disponibles, y querer verlos en el cronograma el dia que se van a usar. Con
   una sola fecha, correr el importe en el cronograma le cambiaba la valuacion,
   y valuarlo bien lo obligaba a mostrarse en el dia de la carga.

   Ahora son dos columnas:

     FECHA              la fecha del dato. VALUA: Cotizacion::ultimaHasta()
                        lee la ultima cotizacion conocida a ESTA fecha. No
                        cambia de significado ni de nombre, asi que el historial
                        y los dos indices que ya existian siguen sirviendo.

     FECHA_CRONOGRAMA   donde cae el importe en el eje del tablero y en la
                        grilla. Es editable desde la pantalla.

   POR QUE VALUA LA DE REGISTRO Y NO LA DE CRONOGRAMA

   Porque la fecha de cronograma es una decision de PRESENTACION -en que dia
   quiero ver este importe- y si valuara, mover una fila en la grilla cambiaria
   la plata. Ese es justamente el acople que este cambio viene a romper. El
   criterio de valuacion no se toca: sigue siendo el que documenta el encabezado
   de cashflow/Class/Cotizacion.php.

   ----------------------------------------------------------------------------
   LA VIGENCIA PASA A SER POR FECHA_CRONOGRAMA

   La regla era "un importe vigente por FECHA". Con dos fechas hay que elegir, y
   la que manda es la del CRONOGRAMA: el significado de la regla es "una fila por
   columna del eje, nada se cuenta dos veces", y eso ahora lo decide el
   cronograma. La fecha de registro pasa a ser metadato, como USUARIO y
   FECHA_ALTA.

   NO HAY UPDATE NI BAJA FISICA, y eso no cambia: cargar -o editar- un dia de
   cronograma que ya tiene importe marca VIGENTE = 0 el anterior e inserta una
   fila nueva, en UNA transaccion. El historial es lo unico que explica por que
   el numero de ayer era otro.

   El indice de lectura acompana: la consulta de cada carga del tablero pasa a
   ser "los vigentes, por fecha de cronograma". El indice viejo por
   (VIGENTE, FECHA) NO se borra: la valuacion sigue leyendo esa columna.

   ----------------------------------------------------------------------------
   LAS FILAS QUE YA ESTAN

   Quedan con la MISMA fecha en los dos campos, asi que el dia que corras esto
   el tablero no se mueve ni un peso. Verificado antes de escribirlo: las cargas
   vigentes se valuan por FECHA -que no cambia- y se ubicaban por FECHA -que
   pasa a ser FECHA_CRONOGRAMA con el mismo valor-.

   SALDO DE INVERSIONES NO RECIBE ESTA COLUMNA, aunque comparta la plomeria de
   OtrosIngresos.php. No es un olvido: ese saldo es un STOCK y su importe ya se
   ubica en el primer dia del eje y no en su fecha
   -OtrosIngresosProvider::stockInversiones()-, asi que una columna de
   cronograma ahi seria una columna que no hace nada y que alguien va a editar
   esperando que haga algo. La diferencia entre los dos circuitos queda declarada
   en la constante del concepto, en OtrosIngresos.php, y en ningun otro lado.

   ----------------------------------------------------------------------------
   ES REEJECUTABLE: cada paso pregunta si ya esta hecho. Correrlo dos veces no
   cambia ningun dato.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_DOLARES_COMITENTE', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_DOLARES_COMITENTE. Corre primero sql/cashflow_dolares_comitente.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La columna, primero NULL para poder poblarla.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_DOLARES_COMITENTE', 'FECHA_CRONOGRAMA') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
        ADD FECHA_CRONOGRAMA DATE NULL;
END
GO

/* ----------------------------------------------------------------------------
   2. Las filas que ya estan: la misma fecha en los dos campos.

   Va sobre TODAS las filas y no solo sobre las vigentes: el historial tambien
   se lee por fecha de cronograma, asi que una version pisada sin el campo
   quedaria fuera del historial de su propio dia.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
SET FECHA_CRONOGRAMA = FECHA
WHERE FECHA_CRONOGRAMA IS NULL;
GO

/* ----------------------------------------------------------------------------
   3. Recien ahora NOT NULL. Una fila sin fecha de cronograma no tendria columna
      donde mostrarse y desapareceria del tablero sin aviso.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_DOLARES_COMITENTE')
      AND name = 'FECHA_CRONOGRAMA'
      AND is_nullable = 1
)
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
        ALTER COLUMN FECHA_CRONOGRAMA DATE NOT NULL;
END
GO

/* ----------------------------------------------------------------------------
   4. El indice de lectura. La consulta que corre en cada carga del tablero pasa
      a ser "los vigentes, por fecha de cronograma". FECHA va en el INCLUDE
      porque la valuacion la necesita en la misma pasada.

      El indice viejo IX_RO_T_CF_DOLCOM_VIGENTE (VIGENTE, FECHA) se queda: la
      tabla se sigue consultando por FECHA.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_RO_T_CF_DOLCOM_CRONO'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_DOLARES_COMITENTE')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_DOLCOM_CRONO
        ON dbo.RO_T_CASHFLOW_DOLARES_COMITENTE (VIGENTE, FECHA_CRONOGRAMA)
        INCLUDE (IMPORTE_USD, FECHA);
END
GO

/* ----------------------------------------------------------------------------
   5. Control: no puede quedar mas de un importe vigente por dia de cronograma.
      Si esto devuelve filas, la regla de vigencia ya estaba rota antes de
      correr el script y hay que mirarlo antes de seguir.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT FECHA_CRONOGRAMA
    FROM dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
    WHERE VIGENTE = 1
    GROUP BY FECHA_CRONOGRAMA
    HAVING COUNT(*) > 1
)
BEGIN
    RAISERROR('ATENCION: hay dias de cronograma con mas de un importe vigente. Revisalos antes de usar la pantalla.', 16, 1);
END
GO

PRINT 'Fecha de cronograma de dolares en cuenta comitente lista.';
GO
