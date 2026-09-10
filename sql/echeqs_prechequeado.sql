/* ============================================================================
   MODULO CASHFLOW - PESTANA ECHEQS
   Maestro de venta cobrada anticipada (pre-chequeado) y vista de neteo
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_ECHEQ_ / RO_V_CASHFLOW_
   Orden   : se puede correr en cualquier momento. No depende de los otros
             scripts. Sin el, la sub-pestana Cheques en Cartera funciona igual
             -sale toda de dbo.SBA14- y la de Venta Cobrada Anticipada avisa que
             faltan las tablas en lugar de romperse.
   ----------------------------------------------------------------------------
   QUE CREA, EN ESTE ORDEN

     1. RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE  que clientes operan pre-chequeados
     2. RO_T_CASHFLOW_ECHEQ_PRECHEQ          excepciones por cheque
     3. RO_V_CASHFLOW_VENTAS_PRECHEQ         los cheques efectivamente marcados

   No crea ningun parametro clave/valor: 'dias_prechequeado' ya existe y lo
   siembra sql/ventas_proyeccion.sql.

   ----------------------------------------------------------------------------
   QUE ES ESTO

   Hay clientes que pagan la mercaderia ANTES de que se les facture: entregan
   echeqs por adelantado. Esa cobranza ya esta en la casa, asi que cuando el
   motor de Ventas proyecta la cobranza de la venta futura de ese cliente, la
   estaria contando de nuevo.

   El circuito es:

     + importe   fila "Echeqs en cartera" del tablero   (la plata existe)
     - importe   serie COBRANZA de Ventas               (la venta ya se prepago)
     ------------------------------------------------
     = contado una sola vez

   Por eso un mismo cheque en estado 'C' aparece en las DOS sub-pestanas de
   Echeqs. Es correcto y da el numero justo: no es un dato repetido.

   ----------------------------------------------------------------------------
   SE GUARDA LA DECISION, NUNCA EL IMPORTE

   NINGUNA de las dos tablas guarda IMPORTE ni FECHA_CHEQUE. Los dos se leen de
   dbo.SBA14 al calcular, y es deliberado:

     - Si el cheque se rechaza o se anula, deja de netear SOLO. Con el importe
       copiado habria que acordarse de limpiarlo, y nadie se acuerda.
     - Si el importe se corrige en Tango, el neteo se corrige con el.

   Es el mismo criterio del resto del modulo: se guarda el criterio del usuario y
   se deriva el numero.

   ----------------------------------------------------------------------------
   TRES TRAMPAS DEL ESQUEMA DE SBA14 QUE ESTE SCRIPT TIENE QUE RESPETAR

   1. La PK de dbo.SBA14 es ID_SBA14 (int). N_CHEQUE NO identifica un cheque: se
      repite entre bancos y entre anios. Por eso la tabla de excepciones se
      referencia por ID_SBA14.

   2. dbo.SBA14.CLIENTE es VARCHAR(6) COLLATE Latin1_General_BIN. Toda columna
      nuestra que se joinee contra ella tiene que declararse con LA MISMA
      collation o SQL Server tira "Cannot resolve the collation conflict".

   3. dbo.SBA14.N_CHEQUE es ENTEROXL_TG, que es un alias de float(53). Va
      CAST(... AS BIGINT) en toda consulta que lo muestre, o el numero de cheque
      sale en notacion cientifica.

   ----------------------------------------------------------------------------
   ES REEJECUTABLE
   Las tablas se crean solo si no existen y la vista va con CREATE OR ALTER, asi
   que una segunda corrida no duplica nada ni pisa ninguna marca ya cargada.

   NO HAY BAJAS FISICAS en el maestro: se inhabilita con ACTIVO = 0.

   Todas las tablas llevan USUARIO VARCHAR(50) NULL. Todavia no hay login, por lo
   que se graba NULL; los metodos de guardado de PHP ya reciben $usuario.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
   Clientes que operan con venta cobrada anticipada.

   ES LO QUE ACOTA la sub-pestana Venta Cobrada Anticipada: sin clientes
   cargados, esa pantalla se muestra VACIA. No es un bug. Una pantalla que por
   defecto tildara los cheques de todos los clientes netearia contra ventas que
   nadie prepago.

   La clave es el CODIGO (dbo.SBA14.CLIENTE), no la razon social: RAZON_EMIS es
   texto tipeado cheque por cheque y cambia entre cheques del mismo cliente.
   RAZON_SOCIAL queda como dato informativo de la pantalla y se trae de GVA14 en
   el alta, que es ademas donde se valida que el codigo exista: un codigo tipeado
   mal no da error, da una lista vacia y nadie entiende por que.

   La collation tiene que ser la de dbo.SBA14.CLIENTE (ver trampa 2 arriba).
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE (
        CLIENTE      VARCHAR(6) COLLATE Latin1_General_BIN NOT NULL,
        RAZON_SOCIAL VARCHAR(60) NULL,
        ACTIVO       BIT         NOT NULL CONSTRAINT DF_CF_ECHEQ_PRECLI_ACTIVO DEFAULT (1),
        FECHA_UPDATE DATETIME    NOT NULL CONSTRAINT DF_CF_ECHEQ_PRECLI_FUPD   DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50) NULL,
        CONSTRAINT PK_ECHEQ_PRECHEQ_CLIENTE PRIMARY KEY CLUSTERED (CLIENTE)
    );
END
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_ECHEQ_PRECHEQ
   Excepciones por cheque.

   Los cheques de un cliente del maestro entran TILDADOS por defecto: estar en el
   maestro es haber optado por la modalidad, y lo que el usuario hace normalmente
   es DESTILDAR los pocos cheques que no corresponden. Esta tabla guarda
   unicamente esos destildes, y el re-tilde posterior, para que quede la
   trazabilidad de quien fue y cuando.

   Por eso la ausencia de fila significa MARCADO: la regla completa es
   COALESCE(MARCADO, 1). Una tabla que guardara los tildes en vez de las
   excepciones obligaria a insertar una fila por cheque para no cambiar nada.

   SIN FK REAL CONTRA dbo.SBA14, a proposito. Es una tabla de Tango: si un cheque
   se depura, una FK nuestra haria fallar la depuracion de un sistema que no es
   nuestro. Una marca huerfana es inofensiva porque no aparece en ningun join.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ (
        ID_SBA14     INT         NOT NULL,
        MARCADO      BIT         NOT NULL,
        FECHA_UPDATE DATETIME    NOT NULL CONSTRAINT DF_CF_ECHEQ_PRECHEQ_FUPD DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50) NULL,
        CONSTRAINT PK_ECHEQ_PRECHEQ PRIMARY KEY CLUSTERED (ID_SBA14)
    );
END
GO

/* ----------------------------------------------------------------------------
   3. RO_V_CASHFLOW_VENTAS_PRECHEQ
   Los cheques EFECTIVAMENTE MARCADOS, que son los que netean la cobranza
   proyectada de Ventas.

   ES LA VISTA ORIGEN que el comentario de Ventas::getNeteoPrechequeado() daba
   por inexistente. Reemplaza como origen de datos a la tabla
   RO_T_CASHFLOW_VENTAS_PRECHEQ de sql/ventas_proyeccion.sql, que queda sin uso.

   'dias_prechequeado' NO ESTA ACA. La vista devuelve la fecha del cheque y el
   PHP le resta los dias para obtener la fecha teorica de factura:

       FECHA_TEORICA_FACTURA = FECHA_CHEQUE - dias_prechequeado

   Es un parametro editable, se lee con Parametros::num() como todos los demas, y
   un parametro leido desde dos lugares es un parametro que se va a
   desincronizar.

   TAMPOCO devuelve el canal. El canal se deriva del prefijo del codigo de
   cliente y esa regla vive en Echeqs::canalDeCliente(), en un solo lugar.

   NO FILTRA ESTADO = 'C'. Los cheques pre-chequeados suelen estar en 'A', que en
   dbo.SBA14 es "aplicado": ya salio de cartera, sea depositado en el banco
   (T_COMP_SAL = 'BDE') o endosado a un proveedor en una orden de pago
   (T_COMP_SAL = 'O/P'). Se excluyen solo 'X' (anulado) y 'R' (rechazado): un
   cheque rechazado no netea nada, y deja de netear SOLO, sin tocar su marca.

   ESTADO viaja en la vista a proposito: es lo que permite auditar cuanto del
   neteo sale de cheques que ya no estan en cartera. Ver el aviso que deja
   Ventas::getNeteoPrechequeado() y la nota de README-ventas.md.

   ESTA VISTA Y Echeqs::cruzarPrechequeado() SON LAS DOS CARAS DE LA MISMA REGLA.
   La de PHP es la que dibuja la pantalla; esta es la que alimenta el neteo y se
   puede consultar desde SQL. Si una cambia, hay que cambiar la otra, y
   tests/test_echeqs.php verifica con datos reales que dan el mismo total.
   ---------------------------------------------------------------------------- */
GO
CREATE OR ALTER VIEW dbo.RO_V_CASHFLOW_VENTAS_PRECHEQ AS
SELECT s.ID_SBA14,
       CAST(s.N_CHEQUE AS BIGINT)  AS N_CHEQUE,
       CAST(s.FECHA_CHEQ AS DATE)  AS FECHA_CHEQUE,
       CAST(s.IMPORTE_CH AS FLOAT) AS IMPORTE,
       s.CLIENTE                   AS COD_CLIENTE,
       s.RAZON_EMIS                AS CLIENTE,
       s.ESTADO
FROM dbo.SBA14 AS s
INNER JOIN dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE AS c
        ON c.CLIENTE = s.CLIENTE
       AND c.ACTIVO = 1
LEFT JOIN dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ AS e
       ON e.ID_SBA14 = s.ID_SBA14
WHERE s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)
  AND s.ESTADO NOT IN ('X', 'R')
  AND s.CLIENTE LIKE '[FL]%'
  /* La ausencia de excepcion es un tilde: ver la nota de la tabla 2. */
  AND COALESCE(e.MARCADO, 1) = 1;
GO

PRINT 'Listo: maestro de pre-chequeado, excepciones por cheque y RO_V_CASHFLOW_VENTAS_PRECHEQ.';
GO
