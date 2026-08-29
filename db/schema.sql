-- PetcliniX schema — php-twig-mtier
-- Plain SQL, no migration framework. See https://www.petclinix.tech/petclinix_domainmodel

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'vet', 'admin') NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE owners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address VARCHAR(255) NOT NULL,
    CONSTRAINT fk_owners_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    specialty VARCHAR(100) NOT NULL,
    CONSTRAINT fk_vets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    species VARCHAR(50) NOT NULL,
    breed VARCHAR(100) NULL,
    birth_date DATE NULL,
    photo_url VARCHAR(255) NULL,
    CONSTRAINT fk_pets_owner FOREIGN KEY (owner_id) REFERENCES owners (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE availability (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vet_id INT UNSIGNED NOT NULL,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    CONSTRAINT fk_availability_vet FOREIGN KEY (vet_id) REFERENCES vets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE availability_exceptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vet_id INT UNSIGNED NOT NULL,
    exception_date DATE NOT NULL,
    is_available BOOLEAN NOT NULL DEFAULT FALSE,
    starts_at TIME NULL,
    ends_at TIME NULL,
    CONSTRAINT fk_availability_exceptions_vet FOREIGN KEY (vet_id) REFERENCES vets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pet_id INT UNSIGNED NOT NULL,
    vet_id INT UNSIGNED NOT NULL,
    scheduled_at DATETIME NOT NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    status ENUM('requested', 'confirmed', 'cancelled', 'completed', 'no_show') NOT NULL DEFAULT 'requested',
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULL for cancelled/completed rows so they never collide in the unique key
    -- below; only one active (requested/confirmed) appointment may occupy a given
    -- vet+slot at a time, but a slot is freed again once an appointment leaves the
    -- active set (e.g. cancelled), self-maintained on every status UPDATE.
    active_scheduled_at DATETIME
        GENERATED ALWAYS AS (CASE WHEN status IN ('requested', 'confirmed') THEN scheduled_at ELSE NULL END) STORED,
    CONSTRAINT fk_appointments_pet FOREIGN KEY (pet_id) REFERENCES pets (id) ON DELETE CASCADE,
    CONSTRAINT fk_appointments_vet FOREIGN KEY (vet_id) REFERENCES vets (id) ON DELETE CASCADE,
    UNIQUE KEY uq_appointments_active_vet_slot (vet_id, active_scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE visits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT UNSIGNED NOT NULL UNIQUE,
    diagnosis VARCHAR(255) NULL,
    vaccination VARCHAR(255) NULL,
    notes TEXT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_visits_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    context JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
