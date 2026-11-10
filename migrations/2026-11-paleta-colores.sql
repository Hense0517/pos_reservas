-- Paleta de colores del sistema (Configuración > Paleta de colores)
-- Ejecutar UNA sola vez en la base de datos del sistema (phpMyAdmin > SQL).
-- Hasta que se ejecute, el sistema funciona con la paleta por defecto (Rosa Glamour)
-- y la pantalla de Configuración avisa que falta este paso.

ALTER TABLE configuracion_negocio
    ADD COLUMN paleta_colores VARCHAR(30) NOT NULL DEFAULT 'rosa_glamour';

-- Valores válidos: rosa_glamour, oro_negro, lavanda_spa, verde_botanico,
--                  azul_oceano, coral_atardecer, vino_borgona, grafito_moderno
