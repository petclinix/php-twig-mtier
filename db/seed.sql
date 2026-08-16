-- Seed data for local development.
-- Admin accounts are never self-registered (see plan.md); seed one here so /admin is reachable.
-- Password: admin12345

INSERT INTO users (email, password_hash, role)
VALUES ('admin@petclinix.local', '$2y$10$7U/oBtFF9Ws1oM12EvHfwO5zQx8aEKbbI2L/k1Q0jL5Vrtv.GO31W', 'admin');
