CREATE TABLE IF NOT EXISTS Preferencias_Estudiantes (
    id_estudiante INT NOT NULL,
    nivel_academico VARCHAR(100) NULL,
    presupuesto_maximo DECIMAL(10, 2) NULL,
    PRIMARY KEY (id_estudiante),
    CONSTRAINT preferencias_estudiantes_ibfk_1
        FOREIGN KEY (id_estudiante) REFERENCES Usuarios (id_usuario)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS Disponibilidad_Estudiantes (
    id_disponibilidad INT NOT NULL AUTO_INCREMENT,
    id_estudiante INT NOT NULL,
    dia_semana ENUM('Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo') NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    PRIMARY KEY (id_disponibilidad),
    KEY idx_disponibilidad_estudiante_dia (id_estudiante, dia_semana),
    CONSTRAINT disponibilidad_estudiantes_ibfk_1
        FOREIGN KEY (id_estudiante) REFERENCES Usuarios (id_usuario)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
