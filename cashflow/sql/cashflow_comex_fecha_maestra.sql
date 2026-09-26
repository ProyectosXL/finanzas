/* ============================================================================
   MODULO CASHFLOW - COMERCIO EXTERIOR: LA FECHA VIVE EN EL MAESTRO
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_COMEX_
   Orden   : DESPUES de sql/cashflow_comex_cotiz_edit.sql, que es el que creo
             COTIZ_USD_EDIT sobre RO_T_CASHFLOW_COMEX_CRONO_NAC. Este script no
             la toca, pero la migracion lee esa tabla y conviene que este en su
             forma final. No depende de ningun otro.
             Despues de este no hay nada que correr.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Las dos pestanas de Comercio Exterior dejaban las fechas editadas desde el
   cashflow en una tabla PROPIA -RO_T_CASHFLOW_COMEX_CRONO_NAC.FECHA_PAGO_EDIT
   y .FECHA_NAC_EDIT- y el maestro de la plataforma Comex,
   RO_T_IMPORTACIONES_ENCABEZADO, no se enteraba.

   El resultado era un dato partido en dos: la app de Comercio Exterior mostraba
   una fecha y el cashflow otra, las dos "vigentes", y ninguna pantalla decia
   que existia la otra. Quien movia un pago desde el cashflow lo movia SOLO para
   el cashflow.

   Desde esta entrega el cashflow escribe DIRECTO sobre el maestro:

       fecha estimada de pago            -> RO_T_IMPORTACIONES_ENCABEZADO.FECHA_EST_PAGO
       fecha estimada de nacionalizacion -> RO_T_IMPORTACIONES_ENCABEZADO.FECHA_DESP_ADU

   Hay UN solo lugar donde vive cada fecha, y las dos apps lo leen.

   ----------------------------------------------------------------------------
   LAS COLUMNAS EDIT NO SE BORRAN, PERO SALEN DEL CIRCUITO DE LECTURA

   Este modulo no borra nada. FECHA_PAGO_EDIT, FECHA_PAGO_ORIG, FECHA_NAC_EDIT y
   FECHA_NAC_ORIG quedan en la tabla con lo que tenian: son el registro de que
   se edito desde el cashflow antes de que existiera el historial de abajo, y
   una vez migradas nadie mas las lee.

   RO_T_CASHFLOW_COMEX_CRONO_NAC SIGUE VIVA, y no es un resto: guarda
   COTIZ_USD_EDIT, el override de cotizacion por contenedor, que no se toca en
   esta entrega y no tiene nada que ver con las fechas.

   ----------------------------------------------------------------------------
   POR QUE HACE FALTA UNA TABLA DE HISTORIAL

   Porque ahora se escribe sobre una tabla que NO es del cashflow. El maestro es
   de la plataforma Comex y no tiene columnas de auditoria: no hay donde decir
   que esa fecha la movio alguien desde el tablero de fondos, y sin eso, tres
   meses despues, una fecha corrida a mano desde el cashflow y una calculada por
   la app de Comex son indistinguibles.

   RO_T_CASHFLOW_COMEX_FECHA_EDIT es ese rastro, y vive del lado del cashflow
   porque es el cashflow el que tiene algo que declarar: quien edito, cuando, y
   que decia la fecha ANTES de que la pisara.

   NO ES UNA SEGUNDA FUENTE DE VERDAD. La fecha vigente es la del maestro,
   siempre. Esta tabla no se consulta para saber que fecha aplica; se consulta
   para saber QUIEN la puso. Si la app de Comex vuelve a mover la fecha, el
   rastro queda describiendo una edicion que ya no es la vigente, y el codigo lo
   detecta comparando FECHA_NUEVA contra el maestro: ahi la pestana deja de
   mostrar la marca de "editada desde el cashflow", porque el valor que se ve ya
   no lo puso el cashflow.

   ----------------------------------------------------------------------------
   NO HAY BAJAS FISICAS, Y EL HISTORIAL ES EL PUNTO

   Editar dos veces la misma fecha no pisa la fila: marca VIGENTE = 0 la
   anterior e inserta una nueva. Es el mismo criterio de
   RO_T_CASHFLOW_ECHEQ_EXCLUIDO y de RO_T_CASHFLOW_COBERTURA_APLIC, por la misma
   razon: con un UPDATE, un dedazo corregido a los cinco minutos y una decision
   que estuvo vigente tres semanas son indistinguibles despues del hecho, y la
   segunda es la que explica por que el egreso proyectado de la semana pasada
   caia en otra columna.

   Por eso la PK es un ID y no (ID_MG, CAMPO): un contenedor puede tener varias
   ediciones de la misma fecha a lo largo del tiempo, y solo una vigente.

   SIN FK REAL CONTRA RO_T_IMPORTACIONES_ENCABEZADO, a proposito y por el mismo
   motivo que las otras tablas del modulo contra las de Tango: es una tabla de
   otra plataforma, y si un contenedor se depura alla, una FK nuestra haria
   fallar una depuracion que no es nuestra. Un rastro huerfano es inofensivo: no
   aparece en ningun join. De hecho esta base YA tiene dos
   -ver el paso 4-.

   USUARIO VARCHAR(50) NULL porque todavia no hay login y se graba NULL. El
   metodo de guardado de PHP ya recibe $usuario, igual que el resto del modulo.

   ----------------------------------------------------------------------------
   LA MIGRACION: QUE PASA CON LO QUE YA ESTABA EDITADO

   Verificado contra la base el 19/09/2026, antes de escribir este script:

       7 filas en RO_T_CASHFLOW_COMEX_CRONO_NAC
       5 con FECHA_PAGO_EDIT cargada
       4 con FECHA_NAC_EDIT distinta del centinela '1900-01-01'
       0 con COTIZ_USD_EDIT

   que dan 9 ediciones a migrar -cada fila puede tener las dos fechas-, y de
   esas 9:

       3 (las dos del ID_MG 558 y la de nacionalizacion del 560) apuntan a
         contenedores QUE YA NO ESTAN en el maestro. No hay adonde migrarlas.
       6 coinciden EXACTAMENTE con lo que el maestro ya dice.
       0 estan en conflicto, y 0 caen sobre un maestro vacio.

   O sea que en esta base la migracion NO ESCRIBE NI UNA FECHA en el maestro:
   ya tiene esas seis. Lo unico que deja son los 6 rastros de quien las edito,
   que es justamente lo que hasta ahora no se guardaba en ningun lado. El
   tablero no se mueve un peso por la migracion -otra cosa es lo que si lo
   mueve, y es el filtro de embarque que esta entrega saca; ver
   README-comex.md-.

   EL CRITERIO PARA EL CONFLICTO, que en esta base no se usa pero en otra si:

       maestro vacio            -> se escribe la EDIT. No se pisa nada.
       maestro = EDIT           -> no hay nada que escribir. Se deja el rastro
                                   igual, con la _ORIG guardada como valor
                                   anterior: la pestana venia mostrando ese
                                   tooltip y no tiene por que perderlo.
       maestro <> EDIT          -> NO SE PISA EL MAESTRO. Se deja el rastro con
                                   VIGENTE = 0 y el script lo lista.
       ID_MG sin maestro        -> no se migra. Se lista.

   POR QUE GANA EL MAESTRO EN EL CONFLICTO. Porque no hay forma de saber cual de
   los dos valores es mas nuevo: la tabla del cashflow tiene FECHA_UPDATE y el
   maestro no tiene fecha de modificacion, asi que la antiguedad no se puede
   comparar. Y ante el empate, el maestro es el dato que la app de Comex esta
   mostrando HOY y que su circuito mantiene. Pisarlo con un valor del cashflow
   que puede ser de hace meses seria cambiar en silencio un dato ajeno, que es
   exactamente lo que esta entrega viene a terminar. Lo que se pierde en el
   conflicto es una edicion vieja; lo que se protegeria pisando es una edicion
   igual de vieja, pero ademas sin que nadie lo haya pedido.

   La consecuencia visible del criterio: en una base con conflictos, esas filas
   aparecen listadas al final del script y alguien tiene que mirarlas. El script
   no decide por nadie, y no las esconde.

   ----------------------------------------------------------------------------
   ES REEJECUTABLE: cada paso pregunta si ya esta hecho, y la migracion no
   vuelve a insertar el rastro de una fila que ya migro.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. EL RASTRO DE QUIEN EDITO DESDE EL CASHFLOW
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT (
        ID              INT IDENTITY(1,1) NOT NULL,
        /* El ID del contenedor en RO_T_IMPORTACIONES_ENCABEZADO. Se llama
           ID_MG por continuidad con RO_T_CASHFLOW_COMEX_CRONO_NAC, que es como
           lo nombra el resto del modulo. */
        ID_MG           INT          NOT NULL,
        /* Cual de las dos fechas se edito. Dos codigos y no dos tablas: es el
           mismo gesto sobre el mismo contenedor, con el mismo rastro, y dos
           tablas identicas se desincronizan en la primera correccion. */
        CAMPO           VARCHAR(10)  NOT NULL,
        /* Lo que decia el maestro ANTES. Es NULL cuando no decia nada, que no
           es lo mismo que una fecha: distinguirlo es lo que permite leer el
           historial y saber si el cashflow corrio una fecha o completo una que
           faltaba. */
        FECHA_ANTERIOR  DATE         NULL,
        /* Lo que el cashflow escribio en el maestro. Se guarda para poder
           comparar contra el maestro y saber si el valor vigente sigue siendo
           este: si la app de Comex lo movio despues, este rastro es historia y
           la pestana no tiene que mostrarlo como si describiera lo que se ve. */
        FECHA_NUEVA     DATE         NOT NULL,
        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_COMEXFED_VIGENTE DEFAULT (1),
        USUARIO         VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_COMEXFED_ALTA    DEFAULT (GETDATE()),
        /* Cuando dejo de ser la edicion vigente. Con FECHA_ALTA sola no se
           puede distinguir una fecha que se corrigio a los cinco minutos de una
           que estuvo vigente tres semanas. */
        FECHA_BAJA      DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_FECHA_EDIT PRIMARY KEY CLUSTERED (ID),
        /* La lista cerrada va en la base y no solo en el PHP: el endpoint es
           alcanzable sin pasar por la pantalla, y un CAMPO con un tercer valor
           dejaria un rastro que ninguna pestana sabe leer. */
        CONSTRAINT CK_RO_T_CF_COMEXFED_CAMPO CHECK (CAMPO IN ('PAGO', 'NAC'))
    );

    /* La consulta que corre en cada carga de las dos pestanas es "los rastros
       vigentes, por contenedor". Mismo criterio de indice que la exclusion de
       echeqs. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_COMEXFED_VIGENTE
        ON dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT (VIGENTE, ID_MG, CAMPO);

    PRINT 'Creada dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UN SOLO RASTRO VIGENTE POR CONTENEDOR Y CAMPO.

   El historial vive en las filas con VIGENTE = 0; las vigentes tienen que ser
   una por (contenedor, campo), o la pestana no sabria cual de dos marcas de
   "editada" describe la fecha que se esta viendo.

   Va como INDICE UNICO FILTRADO y no como constraint: un UNIQUE comun
   prohibiria tambien las filas historicas repetidas, que es justamente lo que
   esta tabla existe para guardar. Mismo patron que
   sql/cashflow_cobertura_automatica.sql.

   SE CONTROLA ANTES DE CREARLO: si ya hubiera duplicados -solo puede pasar por
   una correccion a mano sobre la tabla- el CREATE falla y el script se corta a
   la mitad. Se avisa y se sigue.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_RO_T_CF_COMEXFED_VIGENTE'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT')
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT
        WHERE VIGENTE = 1
        GROUP BY ID_MG, CAMPO
        HAVING COUNT(*) > 1
    )
    BEGIN
        PRINT 'ATENCION: hay mas de un rastro vigente para el mismo contenedor y campo.';
        PRINT 'No se creo el indice unico. Revisa que fila corresponde y da de baja las otras (VIGENTE = 0).';
    END
    ELSE
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COMEXFED_VIGENTE
            ON dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT (ID_MG, CAMPO)
            WHERE VIGENTE = 1;

        PRINT 'Creado el indice unico de rastros vigentes.';
    END
END
GO

/* ----------------------------------------------------------------------------
   3. LA MIGRACION: LAS FECHAS EDITADAS PASAN AL MAESTRO

   Las dos mitades -escribir el maestro y dejar el rastro- van en UNA
   transaccion. Si fueran dos escrituras sueltas y fallara la segunda, el
   maestro quedaria con una fecha que el cashflow escribio y sin nada que diga
   que fue el cashflow: exactamente el estado que esta entrega viene a terminar.

   EL CENTINELA '1900-01-01' NO ES UNA EDICION. FECHA_NAC_ORIG y FECHA_NAC_EDIT
   nacieron NOT NULL, asi que updateFechaPago() y updateCotizacion() las
   rellenaban con esa fecha al insertar una fila para la OTRA pestana. Tomarla
   como una edicion migraria al maestro una nacionalizacion en 1900 -y en el
   tablero ese importe caeria fuera del eje sin que nadie entienda por que-.

   'MIGRACION' COMO USUARIO es lo que hace el paso reejecutable y ademas lo deja
   legible: una fila con ese usuario no la cargo nadie desde la pantalla.
   ---------------------------------------------------------------------------- */
DECLARE @migradas INT = 0, @rastros INT = 0;

BEGIN TRANSACTION;

BEGIN TRY

    /* Los candidatos, una sola vez, con las dos fechas puestas en filas
       separadas: asi los dos campos se migran con el mismo codigo en vez de con
       dos bloques iguales que despues divergen. */
    DECLARE @cand TABLE (
        ID_MG          INT,
        CAMPO          VARCHAR(10),
        EDIT           DATE,
        ORIG           DATE,
        MAESTRO        DATE,
        HAY_MAESTRO    BIT
    );

    INSERT INTO @cand (ID_MG, CAMPO, EDIT, ORIG, MAESTRO, HAY_MAESTRO)
    SELECT D.ID_MG, 'PAGO',
           D.FECHA_PAGO_EDIT,
           NULLIF(D.FECHA_PAGO_ORIG, '1900-01-01'),
           A.FECHA_EST_PAGO,
           CASE WHEN A.ID IS NULL THEN 0 ELSE 1 END
    FROM dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC D
    LEFT JOIN dbo.RO_T_IMPORTACIONES_ENCABEZADO A ON A.ID = D.ID_MG
    WHERE D.FECHA_PAGO_EDIT IS NOT NULL
      AND D.FECHA_PAGO_EDIT <> '1900-01-01';

    INSERT INTO @cand (ID_MG, CAMPO, EDIT, ORIG, MAESTRO, HAY_MAESTRO)
    SELECT D.ID_MG, 'NAC',
           D.FECHA_NAC_EDIT,
           NULLIF(D.FECHA_NAC_ORIG, '1900-01-01'),
           A.FECHA_DESP_ADU,
           CASE WHEN A.ID IS NULL THEN 0 ELSE 1 END
    FROM dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC D
    LEFT JOIN dbo.RO_T_IMPORTACIONES_ENCABEZADO A ON A.ID = D.ID_MG
    WHERE D.FECHA_NAC_EDIT IS NOT NULL
      AND D.FECHA_NAC_EDIT <> '1900-01-01';

    /* Lo ya migrado no se vuelve a tocar: es lo que hace el paso
       reejecutable. Se mira por (contenedor, campo) y usuario 'MIGRACION',
       vigente o no: una migracion que despues alguien piso a mano desde la
       pantalla tampoco tiene que volver a correr. */
    DELETE C
    FROM @cand C
    WHERE EXISTS (
        SELECT 1 FROM dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT E
        WHERE E.ID_MG = C.ID_MG AND E.CAMPO = C.CAMPO AND E.USUARIO = 'MIGRACION'
    );

    /* 3.a. El maestro vacio se completa. No se pisa nada: donde no habia
            fecha, la del cashflow es la unica que hay. */
    UPDATE A
    SET A.FECHA_EST_PAGO = C.EDIT
    FROM dbo.RO_T_IMPORTACIONES_ENCABEZADO A
    JOIN @cand C ON C.ID_MG = A.ID AND C.CAMPO = 'PAGO'
    WHERE C.HAY_MAESTRO = 1 AND C.MAESTRO IS NULL;

    SET @migradas = @migradas + @@ROWCOUNT;

    UPDATE A
    SET A.FECHA_DESP_ADU = C.EDIT
    FROM dbo.RO_T_IMPORTACIONES_ENCABEZADO A
    JOIN @cand C ON C.ID_MG = A.ID AND C.CAMPO = 'NAC'
    WHERE C.HAY_MAESTRO = 1 AND C.MAESTRO IS NULL;

    SET @migradas = @migradas + @@ROWCOUNT;

    /* 3.b. El rastro de lo que efectivamente quedo en el maestro: lo que se
            acaba de completar y lo que ya coincidia. VIGENTE = 1 porque
            describe el valor que se ve hoy.

            El caso "ya coincidia" se registra igual, y no es redundante: la
            pestana venia mostrando el badge de editada y el tooltip con la
            fecha original, y sin este rastro esas filas lo perderian sin que
            nada lo diga. */
    INSERT INTO dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT
        (ID_MG, CAMPO, FECHA_ANTERIOR, FECHA_NUEVA, VIGENTE, USUARIO, FECHA_ALTA)
    SELECT C.ID_MG, C.CAMPO, C.ORIG, C.EDIT, 1, 'MIGRACION', GETDATE()
    FROM @cand C
    WHERE C.HAY_MAESTRO = 1
      AND (C.MAESTRO IS NULL OR C.MAESTRO = C.EDIT);

    SET @rastros = @rastros + @@ROWCOUNT;

    /* 3.c. El conflicto: el maestro dice otra cosa y NO se pisa. El rastro
            entra con VIGENTE = 0 porque no describe el valor vigente: es la
            constancia de que hubo una edicion desde el cashflow que quedo
            atras. FECHA_BAJA se sella con el momento de la migracion, que es
            cuando efectivamente dejo de aplicar. */
    INSERT INTO dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT
        (ID_MG, CAMPO, FECHA_ANTERIOR, FECHA_NUEVA, VIGENTE, USUARIO, FECHA_ALTA, FECHA_BAJA)
    SELECT C.ID_MG, C.CAMPO, C.ORIG, C.EDIT, 0, 'MIGRACION', GETDATE(), GETDATE()
    FROM @cand C
    WHERE C.HAY_MAESTRO = 1
      AND C.MAESTRO IS NOT NULL
      AND C.MAESTRO <> C.EDIT;

    SET @rastros = @rastros + @@ROWCOUNT;

    COMMIT TRANSACTION;

    PRINT 'Migracion lista. Fechas escritas en el maestro: ' + CAST(@migradas AS VARCHAR(10))
        + '. Rastros registrados: ' + CAST(@rastros AS VARCHAR(10)) + '.';

END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    PRINT 'ATENCION: la migracion no se aplico. Ni el maestro ni el rastro se tocaron.';

    THROW;
END CATCH
GO

/* ----------------------------------------------------------------------------
   4. LO QUE NO SE MIGRO, PARA QUE ALGUIEN LO MIRE

   Son las dos listas que el criterio de arriba deja afuera a proposito. En la
   base al 19/09/2026 la primera devuelve dos filas -los contenedores 558 y 560,
   que ya no estan en el maestro- y la segunda ninguna.

   Un script que resuelve un conflicto en silencio es peor que uno que lo
   muestra: la decision de que fecha vale no es del script.
   ---------------------------------------------------------------------------- */
SELECT 'edicion sin contenedor en el maestro' AS CASO,
       D.ID_MG, D.FECHA_PAGO_EDIT, D.FECHA_NAC_EDIT, D.FECHA_UPDATE
FROM dbo.RO_T_CASHFLOW_COMEX_CRONO_NAC D
LEFT JOIN dbo.RO_T_IMPORTACIONES_ENCABEZADO A ON A.ID = D.ID_MG
WHERE A.ID IS NULL
  AND (
        (D.FECHA_PAGO_EDIT IS NOT NULL AND D.FECHA_PAGO_EDIT <> '1900-01-01')
     OR (D.FECHA_NAC_EDIT  IS NOT NULL AND D.FECHA_NAC_EDIT  <> '1900-01-01')
  );
GO

SELECT 'el maestro dice otra cosa: NO se piso' AS CASO,
       E.ID_MG, E.CAMPO, E.FECHA_NUEVA AS DECIA_EL_CASHFLOW,
       CASE WHEN E.CAMPO = 'PAGO' THEN A.FECHA_EST_PAGO ELSE A.FECHA_DESP_ADU END AS DICE_EL_MAESTRO
FROM dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT E
JOIN dbo.RO_T_IMPORTACIONES_ENCABEZADO A ON A.ID = E.ID_MG
WHERE E.USUARIO = 'MIGRACION' AND E.VIGENTE = 0;
GO

/* ----------------------------------------------------------------------------
   5. Control final: como quedo.
   ---------------------------------------------------------------------------- */
SELECT COUNT(*) AS RASTROS,
       SUM(CASE WHEN VIGENTE = 1 THEN 1 ELSE 0 END) AS VIGENTES,
       SUM(CASE WHEN CAMPO = 'PAGO' THEN 1 ELSE 0 END) AS DE_PAGO,
       SUM(CASE WHEN CAMPO = 'NAC'  THEN 1 ELSE 0 END) AS DE_NACIONALIZACION
FROM dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT;
GO

PRINT 'Comercio Exterior: las fechas del cashflow ya viven en el maestro.';
GO
