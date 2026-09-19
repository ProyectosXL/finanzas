/* ============================================================================
   MODULO CASHFLOW - COMERCIO EXTERIOR: MARCAR UN PAGO COMO YA HECHO
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_COMEX_
   Orden   : se puede correr en cualquier momento. No depende de ningun otro.
             Sin el, las dos pestanas de Comercio Exterior funcionan
             exactamente como hoy y avisan que no se puede marcar.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   El cashflow proyecta lo que falta pagar. Un contenedor cuyo pago YA SE HIZO
   sigue apareciendo -el maestro de Comercio Exterior no dice si se pago- y su
   importe sigue sumando en el tablero como si fuera un egreso por venir.

   Desde la etapa anterior, ademas, un pago con la fecha vencida no suma pero
   SIGUE EN LA GRILLA, escondido detras del interruptor "Ver vencidas", a la
   espera de que alguien haga algo con el. Este tilde es ese "algo": se marca
   como pagado y deja de molestar, sin inventar una fecha que nadie conoce.

   ----------------------------------------------------------------------------
   EL DATO ES DEL CASHFLOW Y NO SE ESCRIBE EN EL MAESTRO

   A diferencia de las FECHAS -que se escriben sobre
   RO_T_IMPORTACIONES_ENCABEZADO porque son el mismo dato para las dos
   aplicaciones, ver sql/cashflow_comex_fecha_maestra.sql- esto es una
   afirmacion DEL CASHFLOW sobre su propia proyeccion: "este egreso ya no lo
   esperamos". Comercio Exterior no tiene hoy ese concepto, y no le vamos a
   agregar una columna a su maestro para un circuito que es nuestro.

   Si mas adelante Comex lo quiere ver, lo resuelve con una consulta contra esta
   tabla: ID_MG es la clave del contenedor en su maestro.

   ----------------------------------------------------------------------------
   POR QUE UNA TABLA PROPIA Y NO COLUMNAS EN RO_T_CASHFLOW_COMEX_CRONO_NAC

   Esa tabla tiene UNA FILA POR CONTENEDOR y no puede llevar historial: pisar la
   marca con un UPDATE haria indistinguibles un tilde puesto por error y
   corregido a los cinco minutos de una decision que estuvo vigente tres
   semanas, y la segunda es la que explica por que el egreso proyectado del mes
   pasado era otro. Es el mismo motivo por el que existen
   RO_T_CASHFLOW_ECHEQ_EXCLUIDO y RO_T_CASHFLOW_COBERTURA_APLIC.

   Y ademas no alcanzaria con un flag: hay DOS pagos por contenedor y son plata
   distinta -lo que se le paga al proveedor del exterior y los gastos de
   nacionalizacion-. Marcar uno no dice nada del otro. Por eso la clave es
   (ID_MG, CONCEPTO) y no ID_MG solo.

   RO_T_CASHFLOW_COMEX_CRONO_NAC no se toca: sigue guardando COTIZ_USD_EDIT.

   ----------------------------------------------------------------------------
   EL IMPORTE NO DESAPARECE: CAMBIA DE SERIE

   Mismo criterio que la exclusion de cheques de cartera -ver el encabezado de
   sql/cashflow_echeqs_excluir.sql- y por la misma razon: un importe que sale
   del tablero sin dejar rastro es un agujero que nadie puede auditar.

       PAGOS + PAGOS_PAGADOS = PAGOS_TODO
       NACIONALIZACION + NACIONALIZACION_PAGADAS = NACIONALIZACION_TODO

   La fila del tablero ya esta apuntada a PAGOS y a NACIONALIZACION, asi que NO
   HAY QUE REPUNTAR NADA: esos dos codigos pasan a significar "lo que falta
   pagar", que es lo que significaban mientras no hubiera nada marcado. El dia
   que se corre este script la tabla nace vacia y EL TABLERO NO SE MUEVE NI UN
   PESO.

   El proveedor informa en cada carga cuanto se marco como pagado, con el
   conteo, igual que EcheqsProvider con lo excluido.

   ----------------------------------------------------------------------------
   NO HAY BAJAS FISICAS

   Desmarcar NO borra la fila: marca VIGENTE = 0 y sella FECHA_BAJA. Volver a
   marcar inserta una fila nueva. Por eso la PK es un ID y no (ID_MG, CONCEPTO):
   un pago puede tener varias marcas a lo largo del tiempo, y solo una vigente.

   SIN FK REAL CONTRA RO_T_IMPORTACIONES_ENCABEZADO, igual que el resto de las
   tablas de este modulo contra las de otras plataformas: si un contenedor se
   depura alla, una FK nuestra haria fallar una depuracion que no es nuestra.
   Una marca huerfana es inofensiva: no aparece en ningun join.

   USUARIO VARCHAR(50) NULL porque todavia no hay login y se graba NULL. El
   metodo de guardado de PHP ya recibe $usuario.

   LA OBSERVACION ES OPCIONAL, a diferencia del motivo de la exclusion de
   echeqs, que es NOT NULL. Alla el tilde saca plata del disponible y hay que
   poder explicar por que; aca el tilde dice que un pago YA SE HIZO, que es un
   hecho y no una decision discutible. Obligar a escribir algo terminaria en
   doscientas filas que dicen "pagado".

   ----------------------------------------------------------------------------
   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LA TABLA
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_PAGADO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COMEX_PAGADO (
        ID          INT IDENTITY(1,1) NOT NULL,
        /* El ID del contenedor en RO_T_IMPORTACIONES_ENCABEZADO. Se llama ID_MG
           por continuidad con el resto del modulo. */
        ID_MG       INT          NOT NULL,
        /* Cual de los dos pagos del contenedor. Son plata distinta: marcar uno
           no dice nada del otro. Mismos codigos que
           RO_T_CASHFLOW_COMEX_FECHA_EDIT.CAMPO, a proposito: es el mismo par de
           conceptos y dos vocabularios distintos para lo mismo se confunden. */
        CONCEPTO    VARCHAR(10)  NOT NULL,
        /* Opcional: ver la nota del encabezado sobre por que no es NOT NULL. */
        OBSERVACION VARCHAR(200) NULL,
        VIGENTE     BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_COMEXPAG_VIGENTE DEFAULT (1),
        USUARIO     VARCHAR(50)  NULL,
        FECHA_ALTA  DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_COMEXPAG_ALTA    DEFAULT (GETDATE()),
        /* Cuando se desmarco. Con FECHA_ALTA sola no se puede distinguir un
           tilde que se deshizo a los cinco minutos de uno que estuvo vigente
           tres semanas. */
        FECHA_BAJA  DATETIME     NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COMEX_PAGADO PRIMARY KEY CLUSTERED (ID),
        /* La lista cerrada va tambien en la base: el endpoint es alcanzable sin
           pasar por la grilla, y un CONCEPTO con un tercer valor dejaria una
           marca que ninguna pestana sabe leer y que ninguna serie descuenta. */
        CONSTRAINT CK_RO_T_CF_COMEXPAG_CONCEPTO CHECK (CONCEPTO IN ('PAGO', 'NAC'))
    );

    /* La consulta que corre en cada carga de las dos pestanas y del tablero es
       "las marcas vigentes, por contenedor". Mismo criterio de indice que la
       exclusion de echeqs. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_COMEXPAG_VIGENTE
        ON dbo.RO_T_CASHFLOW_COMEX_PAGADO (VIGENTE, ID_MG, CONCEPTO);

    PRINT 'Creada dbo.RO_T_CASHFLOW_COMEX_PAGADO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_COMEX_PAGADO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. UNA SOLA MARCA VIGENTE POR CONTENEDOR Y CONCEPTO.

   El historial vive en las filas con VIGENTE = 0; las vigentes tienen que ser
   una por (contenedor, concepto), o el proveedor contaria el mismo importe dos
   veces en la serie de pagados.

   Va como INDICE UNICO FILTRADO y no como constraint: un UNIQUE comun
   prohibiria tambien las filas historicas repetidas, que es justamente lo que
   esta tabla existe para guardar.

   SE CONTROLA ANTES DE CREARLO: si ya hubiera duplicados -solo puede pasar por
   una correccion a mano sobre la tabla- el CREATE falla y el script se corta a
   la mitad. Se avisa y se sigue.
   ---------------------------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'UX_RO_T_CF_COMEXPAG_VIGENTE'
      AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_PAGADO')
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM dbo.RO_T_CASHFLOW_COMEX_PAGADO
        WHERE VIGENTE = 1
        GROUP BY ID_MG, CONCEPTO
        HAVING COUNT(*) > 1
    )
    BEGIN
        PRINT 'ATENCION: hay mas de una marca vigente para el mismo contenedor y concepto.';
        PRINT 'No se creo el indice unico. Revisa cual corresponde y da de baja las otras (VIGENTE = 0).';
    END
    ELSE
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COMEXPAG_VIGENTE
            ON dbo.RO_T_CASHFLOW_COMEX_PAGADO (ID_MG, CONCEPTO)
            WHERE VIGENTE = 1;

        PRINT 'Creado el indice unico de marcas vigentes.';
    END
END
GO

/* ----------------------------------------------------------------------------
   3. Control final: como quedo.

   En una instalacion nueva devuelve todo en cero, y eso es lo correcto: la
   tabla nace vacia y el tablero no se mueve.
   ---------------------------------------------------------------------------- */
SELECT COUNT(*) AS MARCAS,
       SUM(CASE WHEN VIGENTE = 1 THEN 1 ELSE 0 END) AS VIGENTES,
       SUM(CASE WHEN VIGENTE = 1 AND CONCEPTO = 'PAGO' THEN 1 ELSE 0 END) AS PAGOS_AL_EXTERIOR,
       SUM(CASE WHEN VIGENTE = 1 AND CONCEPTO = 'NAC'  THEN 1 ELSE 0 END) AS NACIONALIZACIONES
FROM dbo.RO_T_CASHFLOW_COMEX_PAGADO;
GO

PRINT 'Comercio Exterior: ya se puede marcar un pago como hecho.';
GO
