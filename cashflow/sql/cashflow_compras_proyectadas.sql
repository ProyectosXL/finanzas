/* ============================================================================
   MODULO CASHFLOW - COMPRAS PROYECTADAS DEL EXTERIOR
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_estructura.sql (crea las tablas de la
            estructura) y, si se quiere el renglon agrupado, DESPUES de
            sql/cashflow_estructura_grupos.sql. Si el de grupos no se corrio,
            este script CREA IGUAL las dos filas nuevas -sueltas, sin grupo- y
            lo avisa: el tablero funciona con cuatro filas en vez de dos
            renglones que se abren, y ningun importe cambia.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   1. Dos filas nuevas al tablero, cada una PEGADA a su fila real:
        PROV_EXTERIOR_PROY     -> COMPRAS_PROY / PAGOS_PROYECTADOS
        NACIONALIZACIONES_PROY -> COMPRAS_PROY / NACIONALIZACION_PROYECTADA
   2. Los dos grupos, que ponen cada par en un solo renglon que se abre.
   3. La tabla de ajustes manuales por mes.
   4. Los parametros del modulo nuevo.

   QUE PROYECTAN ESAS FILAS, EN UNA LINEA

   Los pagos de FOB y de nacionalizacion de las compras del exterior que
   TODAVIA NO TIENEN CONTENEDOR cargado en Comercio Exterior, a partir del
   presupuesto oficial de la app de compras. Lo proyectado es SOLO la
   diferencia entre lo presupuestado y lo ya comprado.

   LAS FILAS EXISTENTES NO SE TOCAN. Proveedores Exterior y Nacionalizaciones
   siguen trayendo exactamente lo que traen hoy -los contenedores cargados,
   ubicados por sus fechas del maestro- y este script solo les declara su
   NATURALEZA. Ningun importe de esas dos filas cambia.

   ----------------------------------------------------------------------------
   POR QUE ORDEN 15 Y 25, Y NO 30 Y 40

   El agrupamiento del tablero es POSICIONAL: filas consecutivas, de la misma
   seccion, del mismo TIPO y con el mismo GRUPO forman un grupo. Hoy
   PROV_EXTERIOR esta en el ORDEN 10 y NACIONALIZACIONES en el 20, asi que los
   huecos 15 y 25 dejan a cada fila nueva pegada a su par sin mover ninguna
   fila existente.

   ESTE SCRIPT NO REORDENA NADA. El orden de las filas del tablero es una
   decision de quien lo configura; moverlas desde aca cambiaria el cuadro sin
   que nadie lo haya pedido. Antes de insertar verifica que 15 y 25 esten
   libres, y al final controla que no haya quedado ninguna fila activa entre
   las dos partes de cada grupo. Si la hay, lo imprime y se resuelve desde
   Parametros -> Cashflow con las flechas. Mismo criterio que el control del
   script de grupos.

   ----------------------------------------------------------------------------
   LOS PARAMETROS, Y POR QUE ESTOS VALORES

   compras_proy_meses              6   Meses de recepcion que se proyectan.
   compras_proy_anios_cuota        3   Anios calendario completos de historia
                                       de recepciones con los que se arma la
                                       cuota. Con uno solo la cuota es
                                       inestable: abril fue 23,66 % en 2023 y
                                       11,10 % en 2025.
   compras_proy_base_cuota  IMPORTE    Con que se reparte: IMPORTE (el FOB de
                                       cada recepcion, prorrateado) o UNIDADES.
                                       Las dos difieren hasta 4,5 puntos en un
                                       mes; se reparte plata, asi que manda el
                                       importe.
   compras_proy_dia_llegada       15   Dia del mes en el que se ubica la
                                       recepcion de cada mes proyectado.
   compras_proy_dias_pago         47   Dias entre el pago del FOB y la
                                       recepcion. Sale de la cadena de Comercio
                                       Exterior: embarque +5 al pago y
                                       embarque +52 a la recepcion (45 al
                                       arribo, +5 a la nacionalizacion, +2 a la
                                       recepcion).
   compras_proy_dias_nac           2   Dias entre la nacionalizacion y la
                                       recepcion. Es DIAS_DESP_REC.
   compras_proy_nac_pct           89   Porcentaje de nacionalizacion sobre el
                                       FOB. Ver abajo: es el numero mas
                                       discutible de todo el modulo.

   ESTOS DOS ULTIMOS SON DEL CASHFLOW Y NO DE COMERCIO EXTERIOR. Arrancan con
   el valor que hoy da la cadena de RO_T_IMPORTACIONES_PARAM_CRONOGRAMA, pero
   NO la leen: si alla cambian los dias, aca no cambia nada hasta que alguien
   lo decida. Es a proposito. Los contenedores reales tienen sus fechas
   editadas a mano una por una -medido contra la base, la distancia entre
   FECHA_EST_PAGO y FECHA_DESP_ADU va de -60 a +68 dias, y 13 contenedores se
   nacionalizan ANTES de pagarse- asi que la cadena es un valor por defecto y
   no una regla. Atar la proyeccion a ella haria que un ajuste operativo de la
   otra aplicacion corra plata de mes en el tablero de finanzas sin aviso.

   POR QUE 89 % Y NO EL inc_fob DEL PRESUPUESTO

   La version oficial trae un inc_fob por fila, y seria lo natural. No sirve:
   toma DOS valores en toda la version -0 y 50- y pondera 41 %, con el 30 % del
   FOB en inc_fob = 0, o sea proyectando nacionalizacion CERO para carteras de
   cuero, ojotas, cosmetica, lentes y relojes.

   Los contenedores REALES dan otra cosa. Medido sobre los 59 con FOB >= 20.000
   que tienen estimacion cargada, el cociente nacionalizacion / FOB va de 0,707
   a 1,059 y pondera 0,892. Es coherente: esos gastos son los conceptos 3 a 10
   de la estimacion de Comex, que se calculan como porcentajes del CIF (ver la
   seccion 6 de README-comex.md).

   Con inc_fob, la ventana de hoy proyectaria U$S 1,99 M de nacionalizacion
   donde los contenedores reales indican U$S 5,11 M. El inc_fob se sigue
   leyendo y se muestra en la pestana, para poder ver el contraste; lo que se
   aplica es este parametro.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   La pestana Compras Proyectadas avisa que falta el script y no rompe. Las dos
   filas nuevas no existen, asi que el tablero queda exactamente como hoy: sin
   parte proyectada, y sin ningun numero cambiado. El proveedor esta registrado
   igual, asi que una fila que lo apunte a mano funcionaria con los valores por
   defecto de los parametros.

   ES REEJECUTABLE: cada insercion pregunta por el codigo, cada parametro por
   su clave, cada tabla por OBJECT_ID y cada UPDATE solo toca lo que todavia no
   esta puesto. La segunda corrida no modifica ninguna fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guardas.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NULL
BEGIN
    RAISERROR('Corre primero sql/cashflow_estructura.sql: no existe RO_T_CASHFLOW_CONF_FILA.', 16, 1);
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NULL
BEGIN
    RAISERROR('Corre primero sql/cashflow_estructura.sql: no existe RO_T_CASHFLOW_PARAMETROS.', 16, 1);
END
GO

/* ============================================================================
   1. LA TABLA DE AJUSTES MANUALES POR MES

   Un importe en U$S FOB, cargado a mano, que REEMPLAZA la estimacion de un mes.

   SIN BAJAS FISICAS, igual que RO_T_CASHFLOW_ECHEQ_EXCLUIDO: corregir un
   ajuste marca VIGENTE = 0 el anterior e inserta uno nuevo. Con un UPDATE, un
   dedazo corregido a los cinco minutos y una decision que estuvo vigente tres
   semanas son indistinguibles despues del hecho.

   ID_VERSION ES LA PARTE QUE IMPORTA. Guarda contra que version oficial se
   cargo el numero. Si despues cambia la oficial de esa temporada, el ajuste
   DEJA DE APLICARSE y el mes vuelve a la estimacion automatica, marcado
   AJUSTE_DESCARTADO y con aviso: el importe se puso mirando otro presupuesto.
   Es el mismo criterio que Comex::descartaCotizacion() con el override de
   cotizacion, que se tira cuando el pago cambia de mes.

   EL INDICE UNICO VA FILTRADO por VIGENTE = 1. Un UNIQUE comun prohibiria
   tambien las filas historicas repetidas del mismo mes, que es exactamente lo
   que esta tabla existe para guardar.

   MES ES EL MES DE RECEPCION ('YYYY-MM') y no el de pago: es el mes con el que
   se identifica la fila en la grilla de cobertura. El mes de pago se deriva de
   los parametros y cambiaria si alguien los toca, asi que un ajuste guardado
   contra el se despegaria solo de su fila.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE (
        ID          INT IDENTITY(1,1) NOT NULL,
        /* Mes de RECEPCION, 'YYYY-MM'. CHAR(7) y no DATE: es un mes, no un dia,
           y guardarlo como fecha obligaria a elegir un dia que no significa
           nada y que despues habria que acordarse de ignorar. */
        MES         CHAR(7)       NOT NULL,
        IMPORTE_USD DECIMAL(18,2) NOT NULL,
        /* Contra que version oficial se cargo. Sin FK: la version vive en otra
           base (POWER_BI_CONTROL) y en otro servidor. */
        ID_VERSION  INT           NOT NULL,
        TEMPORADA   VARCHAR(20)   NULL,
        MOTIVO      VARCHAR(300)  NULL,
        VIGENTE     BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_CPAJU_VIGENTE DEFAULT (1),
        USUARIO     VARCHAR(50)   NULL,
        FECHA_ALTA  DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_CPAJU_ALTA DEFAULT (GETDATE()),
        FECHA_BAJA  DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE PRIMARY KEY CLUSTERED (ID)
    );

    /* UN SOLO AJUSTE VIGENTE POR MES. Filtrado, por lo dicho arriba. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_CPAJU_MES_VIGENTE
        ON dbo.RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE (MES)
        WHERE VIGENTE = 1;

    PRINT 'RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE ya existia.';
GO

/* ============================================================================
   2. LOS PARAMETROS DEL MODULO

   Se insertan solo los que faltan: si alguien ya ajusto un valor desde
   Parametros, este script no lo pisa.
   ============================================================================ */
DECLARE @tieneModulo BIT =
    CASE WHEN COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL THEN 0 ELSE 1 END;

IF @tieneModulo = 0
    PRINT 'AVISO: RO_T_CASHFLOW_PARAMETROS no tiene columna MODULO. Los parametros se crean '
        + 'igual, pero la pestana Parametros no va a poder agruparlos por modulo.';
GO

/* TIPO_DATO es NOT NULL y lo usa el formulario de Parametros para decidir que
   control dibuja y como valida. Los valores que la tabla ya usa son INT,
   DECIMAL, TEXT y CSV. */
DECLARE @p TABLE (CLAVE VARCHAR(50), VALOR VARCHAR(200), TIPO_DATO VARCHAR(20),
                  DESCRIPCION VARCHAR(200), GRUPO VARCHAR(50));

INSERT INTO @p (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO) VALUES
 ('compras_proy_meses', '6', 'INT',
  'Meses de recepcion que se proyectan. El ultimo lo deriva el servidor: es el ultimo cuyo pago de FOB cae dentro del horizonte',
  'GENERAL'),
 ('compras_proy_anios_cuota', '3', 'INT',
  'Anios calendario completos de historia de recepciones con los que se arma la cuota mensual',
  'GENERAL'),
 ('compras_proy_base_cuota', 'IMPORTE', 'TEXT',
  'Con que se reparte la compra entre los meses: IMPORTE (FOB prorrateado) o UNIDADES',
  'GENERAL'),
 ('compras_proy_nac_pct', '89', 'DECIMAL',
  'Porcentaje de nacionalizacion sobre el FOB. Es el cociente ponderado de los contenedores reales; el inc_fob del presupuesto da 41 % y deja el 30 % del FOB en cero',
  'GENERAL'),
/* LOS TRES DE FECHAS VAN TAMBIEN EN GRUPO 'GENERAL', y no en uno propio.
   La pestana Parametros resuelve la seccion 'generales' pidiendo el GRUPO
   'GENERAL' del modulo; un grupo nuevo necesitaria ademas una seccion nueva en
   Parametros::getModulosConDatos() y su bloque en el front. Son tres campos: no
   justifica tocar el renderizado que comparten seis modulos. */
 ('compras_proy_dia_llegada', '15', 'INT',
  'Dia del mes en el que se ubica la recepcion de cada mes proyectado',
  'GENERAL'),
 ('compras_proy_dias_pago', '47', 'INT',
  'Dias entre el pago del FOB y la recepcion. Valor inicial: el que da hoy la cadena de Comercio Exterior',
  'GENERAL'),
 ('compras_proy_dias_nac', '2', 'INT',
  'Dias entre la nacionalizacion y la recepcion. Valor inicial: DIAS_DESP_REC de Comercio Exterior',
  'GENERAL');

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, FECHA_UPDATE)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, p.GRUPO, GETDATE()
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);
END
ELSE
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO, FECHA_UPDATE)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, p.GRUPO, 'COMPRAS_PROY', GETDATE()
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);

    /* Un parametro que ya existia apuntado a otro modulo se reapunta: NO se le
       toca el VALOR, solo donde se muestra. */
    UPDATE x
    SET MODULO = 'COMPRAS_PROY'
    FROM dbo.RO_T_CASHFLOW_PARAMETROS x
    JOIN @p p ON p.CLAVE = x.CLAVE
    WHERE ISNULL(x.MODULO, '') <> 'COMPRAS_PROY';
END

/* EL GRUPO TAMBIEN SE CORRIGE, Y TAMPOCO TOCA EL VALOR.
   La pestana Parametros resuelve la seccion 'generales' pidiendo el GRUPO
   'GENERAL' del modulo, asi que un parametro con otro grupo EXISTE pero no se
   dibuja: el campo no aparece y la pantalla dice que falta. Una version
   anterior de este script sembraba los tres de dias con GRUPO = 'FECHAS', y sin
   esto una base que la corrio se queda con tres campos invisibles para siempre,
   porque el INSERT de arriba solo crea lo que falta.

   TIPO_DATO va en el mismo UPDATE por el mismo motivo: decide que control
   dibuja el formulario. */
UPDATE x
SET GRUPO = p.GRUPO, TIPO_DATO = p.TIPO_DATO
FROM dbo.RO_T_CASHFLOW_PARAMETROS x
JOIN @p p ON p.CLAVE = x.CLAVE
WHERE ISNULL(x.GRUPO, '') <> p.GRUPO OR ISNULL(x.TIPO_DATO, '') <> p.TIPO_DATO;

/* El contador va a una variable antes del PRINT: PRINT toma una expresion
   escalar y una subconsulta ahi es un error de sintaxis, no de ejecucion, asi
   que el lote entero no llega a correr. */
DECLARE @nParams INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_PARAMETROS
                        WHERE CLAVE LIKE 'compras_proy%');

PRINT 'Parametros de compras proyectadas: ' + CAST(@nParams AS VARCHAR(10)) + ' en la tabla.';
GO

/* ============================================================================
   3. LAS DOS FILAS NUEVAS DEL TABLERO

   Cada una en la MISMA seccion que su fila real y con un ORDEN que la deja
   pegada a ella. Si el hueco esta ocupado por otra fila activa, NO se inserta:
   se avisa y se resuelve desde Parametros.
   ============================================================================ */
DECLARE @seccionExt VARCHAR(40), @ordenExt INT;
DECLARE @seccionNac VARCHAR(40), @ordenNac INT;

SELECT @seccionExt = SECCION, @ordenExt = ORDEN
FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'PROV_EXTERIOR';

SELECT @seccionNac = SECCION, @ordenNac = ORDEN
FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'NACIONALIZACIONES';

IF @seccionExt IS NULL OR @seccionNac IS NULL
BEGIN
    PRINT 'AVISO: no estan las filas PROV_EXTERIOR y/o NACIONALIZACIONES. Las filas '
        + 'proyectadas NO se crean: quedarian huerfanas, sin la parte real al lado. '
        + 'Corre sql/cashflow_estructura.sql.';
END
ELSE
BEGIN
    /* El hueco: el ORDEN de la fila real mas 5. Con los valores de hoy (10 y
       20) da 15 y 25. Se calcula y no se escribe en duro para que el script
       siga valiendo si alguien reordena la seccion antes de correrlo. */
    DECLARE @huecoExt INT = @ordenExt + 5;
    DECLARE @huecoNac INT = @ordenNac + 5;

    DECLARE @chocaExt INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_CONF_FILA
                             WHERE SECCION = @seccionExt AND ORDEN = @huecoExt
                               AND ACTIVO = 1 AND CODIGO <> 'PROV_EXTERIOR_PROY');

    DECLARE @chocaNac INT = (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_CONF_FILA
                             WHERE SECCION = @seccionNac AND ORDEN = @huecoNac
                               AND ACTIVO = 1 AND CODIGO <> 'NACIONALIZACIONES_PROY');

    IF @chocaExt > 0
        PRINT 'AVISO: el ORDEN ' + CAST(@huecoExt AS VARCHAR(10)) + ' de la seccion '
            + @seccionExt + ' ya esta ocupado por otra fila activa. PROV_EXTERIOR_PROY '
            + 'NO se crea. Libera ese orden desde Parametros -> Cashflow.';

    IF @chocaNac > 0
        PRINT 'AVISO: el ORDEN ' + CAST(@huecoNac AS VARCHAR(10)) + ' de la seccion '
            + @seccionNac + ' ya esta ocupado por otra fila activa. NACIONALIZACIONES_PROY '
            + 'NO se crea. Libera ese orden desde Parametros -> Cashflow.';

    IF @chocaExt = 0 AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
                                     WHERE CODIGO = 'PROV_EXTERIOR_PROY')
    BEGIN
        INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
            (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE,
             ORDEN, ACTIVO, FECHA_UPDATE)
        VALUES
            ('PROV_EXTERIOR_PROY', 'Proveedores Exterior Proyectado', @seccionExt, 'EGRESO', 1,
             'COMPRAS_PROY', 'PAGOS_PROYECTADOS', @huecoExt, 1, GETDATE());

        PRINT 'Fila PROV_EXTERIOR_PROY creada en ' + @seccionExt + ', orden '
            + CAST(@huecoExt AS VARCHAR(10)) + '.';
    END

    IF @chocaNac = 0 AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
                                     WHERE CODIGO = 'NACIONALIZACIONES_PROY')
    BEGIN
        INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
            (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE,
             ORDEN, ACTIVO, FECHA_UPDATE)
        VALUES
            ('NACIONALIZACIONES_PROY', 'Nacionalizaciones Proyectado', @seccionNac, 'EGRESO', 1,
             'COMPRAS_PROY', 'NACIONALIZACION_PROYECTADA', @huecoNac, 1, GETDATE());

        PRINT 'Fila NACIONALIZACIONES_PROY creada en ' + @seccionNac + ', orden '
            + CAST(@huecoNac AS VARCHAR(10)) + '.';
    END
END
GO

/* ============================================================================
   4. LOS DOS GRUPOS

   Solo si el script de grupos ya agrego las tres columnas. Si no, las filas
   quedan sueltas: el tablero muestra cuatro filas en vez de dos renglones que
   se abren, y ningun importe cambia -la agrupacion es presentacion-.
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NULL
BEGIN
    PRINT 'AVISO: falta sql/cashflow_estructura_grupos.sql. Las dos filas proyectadas quedan '
        + 'SUELTAS, al lado de su parte real, y el tablero las dibuja por separado. Ningun '
        + 'importe cambia: la agrupacion es presentacion. Corriendo ese script y despues este '
        + 'otra vez, los grupos se arman solos.';
END
ELSE
BEGIN
    /* GRUPO_NOMBRE lo declara UNA sola de las dos: la REAL, que va primero.
       Ponerlo en las dos no rompe -gana la primera- pero deja dos lugares
       donde cambiar el nombre del renglon. Mismo criterio que el grupo de
       Cobranzas Franquicias. */
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = 'PROV_EXTERIOR', NATURALEZA = 'REAL',
        GRUPO_NOMBRE = 'Proveedores Exterior', FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'PROV_EXTERIOR'
      AND (ISNULL(GRUPO, '') <> 'PROV_EXTERIOR'
           OR ISNULL(NATURALEZA, '') <> 'REAL'
           OR ISNULL(GRUPO_NOMBRE, '') <> 'Proveedores Exterior');

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = 'PROV_EXTERIOR', NATURALEZA = 'PROYECTADO',
        GRUPO_NOMBRE = NULL, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'PROV_EXTERIOR_PROY'
      AND (ISNULL(GRUPO, '') <> 'PROV_EXTERIOR'
           OR ISNULL(NATURALEZA, '') <> 'PROYECTADO'
           OR GRUPO_NOMBRE IS NOT NULL);

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = 'NACIONALIZACIONES', NATURALEZA = 'REAL',
        GRUPO_NOMBRE = 'Nacionalizaciones', FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'NACIONALIZACIONES'
      AND (ISNULL(GRUPO, '') <> 'NACIONALIZACIONES'
           OR ISNULL(NATURALEZA, '') <> 'REAL'
           OR ISNULL(GRUPO_NOMBRE, '') <> 'Nacionalizaciones');

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = 'NACIONALIZACIONES', NATURALEZA = 'PROYECTADO',
        GRUPO_NOMBRE = NULL, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'NACIONALIZACIONES_PROY'
      AND (ISNULL(GRUPO, '') <> 'NACIONALIZACIONES'
           OR ISNULL(NATURALEZA, '') <> 'PROYECTADO'
           OR GRUPO_NOMBRE IS NOT NULL);

    PRINT 'Grupos PROV_EXTERIOR y NACIONALIZACIONES asignados.';
END
GO

/* ============================================================================
   5. CONTROL: las dos partes de cada grupo tienen que quedar SEGUIDAS.

   El agrupamiento es posicional, asi que una fila activa metida entre las dos
   deshace el grupo: el tablero las dibuja sueltas y el validador lo avisa.
   Este script NO reordena. Si esto imprime algo, se resuelve desde
   Parametros -> Cashflow con las flechas.
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NOT NULL
BEGIN
    DECLARE @grupo VARCHAR(30), @real VARCHAR(40), @proy VARCHAR(40), @entre INT;

    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
        SELECT 'PROV_EXTERIOR', 'PROV_EXTERIOR', 'PROV_EXTERIOR_PROY'
        UNION ALL
        SELECT 'NACIONALIZACIONES', 'NACIONALIZACIONES', 'NACIONALIZACIONES_PROY';

    OPEN cur;
    FETCH NEXT FROM cur INTO @grupo, @real, @proy;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @entre = NULL;

        SELECT @entre = COUNT(*)
        FROM dbo.RO_T_CASHFLOW_CONF_FILA f
        WHERE f.ACTIVO = 1
          AND ISNULL(f.GRUPO, '') <> @grupo
          AND f.SECCION = (SELECT SECCION FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = @real)
          AND f.ORDEN > (SELECT ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = @real)
          AND f.ORDEN < (SELECT ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = @proy);

        IF @entre > 0
            PRINT 'AVISO: hay ' + CAST(@entre AS VARCHAR(10)) + ' fila(s) activa(s) entre las '
                + 'dos partes de ' + @grupo + ', asi que NO se van a agrupar. Ponelas seguidas '
                + 'desde Parametros -> Cashflow.';

        FETCH NEXT FROM cur INTO @grupo, @real, @proy;
    END

    CLOSE cur;
    DEALLOCATE cur;
END
GO

/* ----------------------------------------------------------------------------
   6. Estado final.
   ---------------------------------------------------------------------------- */
PRINT '';
PRINT '--- Estado ---';

SELECT
    CASE WHEN OBJECT_ID('dbo.RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE', 'U') IS NULL
         THEN 'FALTA' ELSE 'OK' END                                   AS tabla_ajustes,
    (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_PARAMETROS
     WHERE CLAVE LIKE 'compras_proy%')                                AS parametros,
    (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_CONF_FILA
     WHERE CODIGO IN ('PROV_EXTERIOR_PROY', 'NACIONALIZACIONES_PROY') AND ACTIVO = 1)
                                                                      AS filas_proyectadas,
    CASE WHEN COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NULL
         THEN 'sin columnas de grupo' ELSE 'OK' END                   AS grupos;

SELECT CODIGO, NOMBRE, SECCION, TIPO, ORDEN, ACTIVO, ORIGEN_PROVIDER, ORIGEN_SERIE
FROM dbo.RO_T_CASHFLOW_CONF_FILA
WHERE CODIGO IN ('PROV_EXTERIOR', 'PROV_EXTERIOR_PROY',
                 'NACIONALIZACIONES', 'NACIONALIZACIONES_PROY')
ORDER BY SECCION, ORDEN;
GO

PRINT 'Listo.';
GO
