/* ============================================================================
   MODULO CASHFLOW - FILAS AGRUPADAS: GRUPO, NATURALEZA Y GRUPO_NOMBRE
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ejecutar DESPUES de sql/cashflow_estructura.sql. El bloque de
            cobranzas del final ademas necesita
            sql/cashflow_estructura_split_cobranzas_fr.sql, pero si no se
            corrio no falla: lo dice y no hace nada.
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   Hay conceptos del tablero que tienen una parte REAL y una parte PROYECTADA
   -hoy la cobranza de franquicias; manana las compras proyectadas del
   exterior-. Las dos partes se calculan por separado, y tiene que ser asi: son
   dos series del proveedor y dos filas del tablero. Pero para leer el cuadro
   de arriba hacia abajo uno quiere UN renglon por concepto, y recien despues,
   si le interesa, abrirlo.

   Esto agrega las tres columnas con las que se declara eso.

   EL AGRUPAMIENTO ES POSICIONAL, COMO TODO EL RESTO DEL TABLERO
   ------------------------------------------------------------
   NO hay ninguna referencia fila->fila: no existe una "fila padre" que declare
   cuales son sus hijas. Filas CONSECUTIVAS de la misma seccion, del mismo TIPO
   y con el mismo GRUPO forman un grupo, igual que un SUBTOTAL abarca lo que
   tiene en su seccion y un FLUJO_NETO lo que tiene por encima. Ver el
   encabezado de cashflow/Class/CashflowEstructura.php.

   Y LA FILA AGRUPADA ES PRESENTACION. El motor no se entera de que existe: las
   filas del grupo siguen calculando y computando por separado, y la suma la
   arma el front, columna a columna, con las filas que dibuja. Subtotales,
   Flujo Neto, Saldo Final y KPIs dan exactamente lo mismo con el grupo abierto
   o cerrado, porque miden las mismas filas de siempre.

   LAS TRES COLUMNAS
   -----------------
   GRUPO        Codigo corto del grupo. NULL = la fila no se agrupa con nadie,
                que es como quedan todas hasta que alguien las agrupe.
   NATURALEZA   'REAL' o 'PROYECTADO': que parte del concepto es esa fila. Va
                por separado de GRUPO a proposito -una fila puede declarar su
                naturaleza sin estar agrupada con nadie, y de hecho es lo que
                hacen Cobranzas Mayoristas, Exportaciones y Cobranzas
                Electronicas mas abajo-. Sirve para la etiqueta de la fila
                abierta y para la vista "solo real" que viene despues.
   GRUPO_NOMBRE Como se llama el grupo en pantalla. Lo declara CUALQUIERA de
                las filas del grupo y gana la primera no vacia; si ninguna lo
                declara se muestra el codigo. Va en la misma tabla y no en una
                tabla de grupos porque un grupo no es una entidad: es una
                corrida de filas, y una tabla aparte volveria a meter la
                referencia fila->fila que este diseño evita.

   POR QUE NO LLEVAN CHECK NI FK
   -----------------------------
   GRUPO no tiene FK contra nada -no hay tabla de grupos- y no tiene CHECK: es
   un codigo que se tipea desde Parametros y el servidor lo slugifica. Lo que
   si valida el codigo es CashflowEstructura::validar(), que ademas avisa
   cuando un grupo no es consecutivo, mezcla secciones o tipos, o no tiene
   alguna de las dos naturalezas. Esas son advertencias y no errores: pueden
   ser transitorias -una temporada sin presupuesto- y el front dibuja las filas
   sueltas, nunca mal sumadas.

   NATURALEZA si lleva CHECK, por la misma razon que TIPO: es un enumerado
   cerrado. LA LISTA DEL CHECK Y CashflowEstructura::NATURALEZAS CAMBIAN
   JUNTAS.

   SI NO SE CORRE
   --------------
   El tablero funciona exactamente como hoy, sin grupos, y lo avisa.
   CashflowEstructura::tieneColumnasGrupo() sondea las columnas y las lecturas
   se acomodan: no es la misma situacion que una tabla que falta, porque estas
   tablas ya existen y les faltan columnas.

   ES REEJECUTABLE: cada ALTER va dentro de un IF por COL_LENGTH, el CHECK
   pregunta por sys.check_constraints, y la reconfiguracion de cobranzas se
   aplica solo donde el valor todavia no esta puesto.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guarda: sin la tabla no hay nada que hacer.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NULL
BEGIN
    RAISERROR('Corre primero sql/cashflow_estructura.sql: no existe RO_T_CASHFLOW_CONF_FILA.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. GRUPO
   VARCHAR(30), el mismo largo que CODIGO: es una clave interna del mismo tipo
   y se slugifica con el mismo helper.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA
        ADD GRUPO VARCHAR(30) NULL;

    PRINT 'Columna GRUPO agregada a RO_T_CASHFLOW_CONF_FILA.';
END
GO

/* ----------------------------------------------------------------------------
   2. NATURALEZA
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'NATURALEZA') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA
        ADD NATURALEZA VARCHAR(12) NULL;

    PRINT 'Columna NATURALEZA agregada a RO_T_CASHFLOW_CONF_FILA.';
END
GO

/* El CHECK va en un lote aparte del ALTER que crea la columna: dentro del
   mismo, el parser no conoce todavia la columna nueva. */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'NATURALEZA') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints
                    WHERE name = 'CK_RO_T_CASHFLOW_CONF_FILA_NATURALEZA'
                      AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA'))
BEGIN
    /* NULL pasa: la inmensa mayoria de las filas no tiene naturaleza -un
       subtotal no es ni real ni proyectado- y obligarlas a elegir una seria
       pedir un dato que no existe. */
    ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA
        ADD CONSTRAINT CK_RO_T_CASHFLOW_CONF_FILA_NATURALEZA
            CHECK (NATURALEZA IS NULL OR NATURALEZA IN ('REAL', 'PROYECTADO'));

    PRINT 'CHECK de NATURALEZA creado.';
END
GO

/* ----------------------------------------------------------------------------
   3. GRUPO_NOMBRE
   VARCHAR(80), el mismo largo que NOMBRE: es un nombre de pantalla y se lee en
   la misma columna que el nombre de una fila.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO_NOMBRE') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_CONF_FILA
        ADD GRUPO_NOMBRE VARCHAR(80) NULL;

    PRINT 'Columna GRUPO_NOMBRE agregada a RO_T_CASHFLOW_CONF_FILA.';
END
GO

PRINT 'Listo: RO_T_CASHFLOW_CONF_FILA ya puede declarar grupos.';
GO

/* ============================================================================
   4. LA COBRANZA DE FRANQUICIAS, AGRUPADA

   Es el unico concepto del tablero que HOY tiene las dos partes de verdad. Lo
   partio en dos filas sql/cashflow_estructura_split_cobranzas_fr.sql; lo que
   falta es decir que esas dos filas son el mismo concepto.

   NO CAMBIA NINGUN IMPORTE. Las dos filas siguen activas, computando y
   entrando en Total Disponibilidades exactamente igual; lo unico que cambia es
   que el tablero las dibuja en un renglon que se abre.

   ES REEJECUTABLE: cada UPDATE pregunta por el valor que va a poner, asi que
   correrlo dos veces no toca ninguna fila la segunda vez -ni siquiera
   FECHA_UPDATE-.
   ============================================================================ */

IF (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_CONF_FILA
     WHERE CODIGO IN ('COBRANZAS_FR_REAL', 'COBRANZAS_FR_PROY')) < 2
BEGIN
    PRINT 'AVISO: no estan las dos filas de Cobranzas FR (COBRANZAS_FR_REAL y '
        + 'COBRANZAS_FR_PROY). Corre antes sql/cashflow_estructura_split_cobranzas_fr.sql. '
        + 'Las columnas quedaron creadas igual y el tablero funciona como hasta ahora.';
END
ELSE
BEGIN
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;

    /* ---- Las partes activas y la total inhabilitada -------------------------
       Lo normal es que esto ya este asi -lo dejo el script del split- y estos
       dos UPDATE no toquen nada. Van igual, y en la MISMA transaccion, porque
       mientras la total conviva activa con sus dos componentes la estructura
       es invalida: el validador rechaza el doble conteo, y esa ventana no
       tiene que existir para nadie que lea la tabla. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 1, FECHA_UPDATE = GETDATE()
    WHERE CODIGO IN ('COBRANZAS_FR_REAL', 'COBRANZAS_FR_PROY')
      AND ACTIVO = 0;

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET ACTIVO = 0, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'COBRANZAS_FR'
      AND ACTIVO = 1;

    /* ---- El grupo -----------------------------------------------------------
       GRUPO_NOMBRE lo declara UNA sola de las dos, la real, que es la que va
       primero. Ponerlo en las dos no rompe nada -gana la primera- pero deja
       dos lugares donde cambiar el nombre del renglon. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = 'COBRANZAS_FR',
        NATURALEZA = 'REAL',
        GRUPO_NOMBRE = 'Cobranzas Franquicias',
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'COBRANZAS_FR_REAL'
      AND (ISNULL(GRUPO, '') <> 'COBRANZAS_FR'
           OR ISNULL(NATURALEZA, '') <> 'REAL'
           OR ISNULL(GRUPO_NOMBRE, '') <> 'Cobranzas Franquicias');

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = 'COBRANZAS_FR',
        NATURALEZA = 'PROYECTADO',
        GRUPO_NOMBRE = NULL,
        FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'COBRANZAS_FR_PROY'
      AND (ISNULL(GRUPO, '') <> 'COBRANZAS_FR'
           OR ISNULL(NATURALEZA, '') <> 'PROYECTADO'
           OR GRUPO_NOMBRE IS NOT NULL);

    COMMIT TRANSACTION;

    PRINT 'Cobranzas Franquicias: las dos partes quedan agrupadas en un renglon.';
END
GO

/* ----------------------------------------------------------------------------
   5. Control: las dos filas del grupo tienen que quedar SEGUIDAS.

   El agrupamiento es posicional, asi que una fila activa metida entre las dos
   deshace el grupo -el tablero las dibuja sueltas y el validador lo avisa-.
   El script NO reordena: el orden de las filas del tablero es una decision de
   quien lo configura, y moverlas desde aca cambiaria el cuadro sin que nadie
   lo pida. Si esto imprime algo, se resuelve desde Parametros con las flechas.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NOT NULL
BEGIN
    DECLARE @entre INT;

    SELECT @entre = COUNT(*)
    FROM dbo.RO_T_CASHFLOW_CONF_FILA f
    WHERE f.ACTIVO = 1
      AND ISNULL(f.GRUPO, '') <> 'COBRANZAS_FR'
      AND f.SECCION = (SELECT SECCION FROM dbo.RO_T_CASHFLOW_CONF_FILA
                        WHERE CODIGO = 'COBRANZAS_FR_REAL')
      AND f.ORDEN > (SELECT ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA
                      WHERE CODIGO = 'COBRANZAS_FR_REAL')
      AND f.ORDEN < (SELECT ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA
                      WHERE CODIGO = 'COBRANZAS_FR_PROY');

    IF @entre > 0
        PRINT 'AVISO: hay ' + CAST(@entre AS VARCHAR(10)) + ' fila(s) activa(s) entre las dos '
            + 'partes de Cobranzas Franquicias, asi que NO se van a agrupar. Ponelas seguidas '
            + 'desde Parametros -> Cashflow.';
END
GO

/* ============================================================================
   6. LA NATURALEZA DE LAS FILAS QUE NO SE AGRUPAN CON NADIE

   Estas tres NO forman grupo -no tienen dos partes- pero cada una es entera de
   una de las dos naturalezas, y declararlo ahora es lo que va a permitir
   despues una vista de "solo real" sin volver a tocar la configuracion.

   NO CAMBIA NADA EN PANTALLA: la etiqueta Real / Proyectado solo se dibuja en
   las filas abiertas de un grupo.

   Lo que se relevo, y por que estas tres y no otras:
     COB_ELECTRONICOS -> REAL. Son acreditaciones que la procesadora YA
                         informo, con fecha cierta. No hay parte proyectada.
     COBRANZAS_MAY    -> PROYECTADO. Facturas pendientes de Tango a +60 dias (o
                         la fecha manual). No hay circuito de propuestas de
                         pago para mayoristas, asi que no hay parte real.
     EXPORTACIONES    -> PROYECTADO. Facturas pendientes a Tasky, con fecha de
                         cobro estimada.

   Y LAS QUE NO ESTAN: las cuatro filas de Ventas son todas proyectadas pero se
   abren POR CANAL, que es otro eje; los cortes de Comercio Exterior y de
   Echeqs son "ya se pago" y "va a entrar", que tampoco son este. Declararles
   una naturaleza a esas seria empezar a llamar "real y proyectado" a cualquier
   corte de dos.
   ============================================================================ */
UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET NATURALEZA = 'REAL', FECHA_UPDATE = GETDATE()
WHERE CODIGO = 'COB_ELECTRONICOS' AND ISNULL(NATURALEZA, '') <> 'REAL';

UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET NATURALEZA = 'PROYECTADO', FECHA_UPDATE = GETDATE()
WHERE CODIGO IN ('COBRANZAS_MAY', 'EXPORTACIONES')
  AND ISNULL(NATURALEZA, '') <> 'PROYECTADO';
GO

PRINT 'Listo.';
GO
