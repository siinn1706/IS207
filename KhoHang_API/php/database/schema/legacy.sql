-- Generated from SQLAlchemy create_all (app/database.py) + startup index. Do not edit by hand.
CREATE TABLE IF NOT EXISTS company_info (
	id INTEGER NOT NULL, 
	name VARCHAR, 
	logo TEXT, 
	tax_id VARCHAR, 
	address VARCHAR, 
	phone VARCHAR, 
	email VARCHAR, 
	bank_name VARCHAR, 
	bank_account VARCHAR, 
	bank_branch VARCHAR, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_company_info_id ON company_info (id);
CREATE TABLE IF NOT EXISTS warehouses (
	id INTEGER NOT NULL, 
	name VARCHAR NOT NULL, 
	code VARCHAR NOT NULL, 
	address VARCHAR, 
	phone VARCHAR, 
	managers JSON, 
	notes TEXT, 
	is_active BOOLEAN, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id), 
	UNIQUE (code)
);
CREATE INDEX IF NOT EXISTS ix_warehouses_id ON warehouses (id);
CREATE TABLE IF NOT EXISTS suppliers (
	id INTEGER NOT NULL, 
	name VARCHAR NOT NULL, 
	tax_id VARCHAR, 
	address VARCHAR, 
	phone VARCHAR, 
	email VARCHAR, 
	bank_account VARCHAR, 
	bank_name VARCHAR, 
	notes TEXT, 
	outstanding_debt FLOAT, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_suppliers_id ON suppliers (id);
CREATE TABLE IF NOT EXISTS stock_in_records (
	id VARCHAR NOT NULL, 
	warehouse_code VARCHAR NOT NULL, 
	supplier VARCHAR NOT NULL, 
	date VARCHAR NOT NULL, 
	note TEXT, 
	tax_rate FLOAT, 
	payment_method VARCHAR, 
	payment_bank_account VARCHAR, 
	payment_bank_name VARCHAR, 
	items JSON NOT NULL, 
	total_quantity INTEGER, 
	total_amount FLOAT, 
	status VARCHAR, 
	created_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_stock_in_records_warehouse_code ON stock_in_records (warehouse_code);
CREATE INDEX IF NOT EXISTS ix_stock_in_records_created_at ON stock_in_records (created_at);
CREATE TABLE IF NOT EXISTS stock_out_records (
	id VARCHAR NOT NULL, 
	warehouse_code VARCHAR NOT NULL, 
	recipient VARCHAR NOT NULL, 
	purpose VARCHAR NOT NULL, 
	date VARCHAR NOT NULL, 
	note TEXT, 
	tax_rate FLOAT, 
	payment_method VARCHAR, 
	payment_bank_account VARCHAR, 
	payment_bank_name VARCHAR, 
	items JSON NOT NULL, 
	total_quantity INTEGER, 
	total_amount FLOAT, 
	status VARCHAR, 
	created_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_stock_out_records_created_at ON stock_out_records (created_at);
CREATE INDEX IF NOT EXISTS ix_stock_out_records_warehouse_code ON stock_out_records (warehouse_code);
CREATE TABLE IF NOT EXISTS users (
	id VARCHAR NOT NULL, 
	username VARCHAR NOT NULL, 
	email VARCHAR NOT NULL, 
	display_name VARCHAR, 
	avatar_url VARCHAR, 
	password_hash VARCHAR NOT NULL, 
	passkey_hash VARCHAR, 
	role VARCHAR, 
	is_verified BOOLEAN, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE UNIQUE INDEX IF NOT EXISTS ix_users_username ON users (username);
CREATE UNIQUE INDEX IF NOT EXISTS ix_users_email ON users (email);
CREATE TABLE IF NOT EXISTS email_otps (
	id INTEGER NOT NULL, 
	email VARCHAR NOT NULL, 
	purpose VARCHAR NOT NULL, 
	code_hash VARCHAR NOT NULL, 
	expires_at DATETIME NOT NULL, 
	attempts_left INTEGER, 
	consumed_at DATETIME, 
	created_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_email_otps_id ON email_otps (id);
CREATE INDEX IF NOT EXISTS ix_email_otps_email ON email_otps (email);
CREATE TABLE IF NOT EXISTS chat_messages (
	id VARCHAR NOT NULL, 
	conversation_id VARCHAR NOT NULL, 
	sender VARCHAR NOT NULL, 
	text TEXT NOT NULL, 
	reply_to JSON, 
	reactions JSON, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_chat_messages_conversation_id ON chat_messages (conversation_id);
CREATE INDEX IF NOT EXISTS ix_chat_messages_created_at ON chat_messages (created_at);
CREATE TABLE IF NOT EXISTS chatbot_config (
	id INTEGER NOT NULL, 
	avatar_url TEXT, 
	bot_name VARCHAR, 
	bot_description VARCHAR, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_chatbot_config_id ON chatbot_config (id);
CREATE TABLE IF NOT EXISTS rt_conversations (
	id VARCHAR NOT NULL, 
	type VARCHAR NOT NULL, 
	title VARCHAR, 
	related_entity_type VARCHAR, 
	related_entity_id VARCHAR, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS ix_rt_conversations_created_at ON rt_conversations (created_at);
CREATE INDEX IF NOT EXISTS ix_rt_conversations_type ON rt_conversations (type);
CREATE TABLE IF NOT EXISTS items (
	id INTEGER NOT NULL, 
	name VARCHAR NOT NULL, 
	sku VARCHAR NOT NULL, 
	unit VARCHAR NOT NULL, 
	price FLOAT NOT NULL, 
	category VARCHAR NOT NULL, 
	supplier_id INTEGER, 
	min_stock INTEGER, 
	quantity INTEGER, 
	description TEXT, 
	expiry_date DATETIME, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id), 
	FOREIGN KEY(supplier_id) REFERENCES suppliers (id)
);
CREATE INDEX IF NOT EXISTS ix_items_id ON items (id);
CREATE UNIQUE INDEX IF NOT EXISTS ix_items_sku ON items (sku);
CREATE TABLE IF NOT EXISTS user_preferences (
	id INTEGER NOT NULL, 
	user_id VARCHAR NOT NULL, 
	accent_id VARCHAR, 
	light_mode_gradient_id VARCHAR, 
	light_mode_pattern_id VARCHAR, 
	light_mode_pattern_opacity FLOAT, 
	light_mode_pattern_size_px INTEGER, 
	light_mode_pattern_tint VARCHAR, 
	dark_mode_gradient_id VARCHAR, 
	dark_mode_pattern_id VARCHAR, 
	dark_mode_pattern_opacity FLOAT, 
	dark_mode_pattern_size_px INTEGER, 
	dark_mode_pattern_tint VARCHAR, 
	created_at DATETIME, 
	updated_at DATETIME, 
	PRIMARY KEY (id), 
	FOREIGN KEY(user_id) REFERENCES users (id)
);
CREATE UNIQUE INDEX IF NOT EXISTS ix_user_preferences_user_id ON user_preferences (user_id);
CREATE INDEX IF NOT EXISTS ix_user_preferences_id ON user_preferences (id);
CREATE TABLE IF NOT EXISTS rt_conversation_members (
	conversation_id VARCHAR NOT NULL, 
	user_id VARCHAR NOT NULL, 
	role VARCHAR, 
	joined_at DATETIME, 
	is_accepted BOOLEAN, 
	PRIMARY KEY (conversation_id, user_id), 
	FOREIGN KEY(conversation_id) REFERENCES rt_conversations (id), 
	FOREIGN KEY(user_id) REFERENCES users (id)
);
CREATE TABLE IF NOT EXISTS rt_messages (
	id VARCHAR NOT NULL, 
	conversation_id VARCHAR NOT NULL, 
	sender_id VARCHAR NOT NULL, 
	client_message_id VARCHAR NOT NULL, 
	content TEXT NOT NULL, 
	content_type VARCHAR, 
	attachments_json JSON, 
	reply_to_id VARCHAR, 
	created_at DATETIME, 
	edited_at DATETIME, 
	deleted_at DATETIME, 
	PRIMARY KEY (id), 
	FOREIGN KEY(conversation_id) REFERENCES rt_conversations (id), 
	FOREIGN KEY(sender_id) REFERENCES users (id), 
	FOREIGN KEY(reply_to_id) REFERENCES rt_messages (id)
);
CREATE INDEX IF NOT EXISTS ix_rt_messages_created_at ON rt_messages (created_at);
CREATE INDEX IF NOT EXISTS ix_rt_messages_conversation_id ON rt_messages (conversation_id);
CREATE TABLE IF NOT EXISTS stock_transactions (
	id INTEGER NOT NULL, 
	type VARCHAR NOT NULL, 
	item_id INTEGER NOT NULL, 
	quantity INTEGER NOT NULL, 
	note TEXT, 
	timestamp DATETIME, 
	warehouse_code VARCHAR, 
	voucher_id VARCHAR, 
	actor_user_id VARCHAR, 
	PRIMARY KEY (id), 
	FOREIGN KEY(item_id) REFERENCES items (id), 
	FOREIGN KEY(actor_user_id) REFERENCES users (id)
);
CREATE INDEX IF NOT EXISTS ix_stock_transactions_actor_user_id ON stock_transactions (actor_user_id);
CREATE INDEX IF NOT EXISTS ix_stock_transactions_timestamp ON stock_transactions (timestamp);
CREATE INDEX IF NOT EXISTS ix_stock_transactions_warehouse_code ON stock_transactions (warehouse_code);
CREATE INDEX IF NOT EXISTS ix_stock_transactions_voucher_id ON stock_transactions (voucher_id);
CREATE INDEX IF NOT EXISTS ix_stock_transactions_id ON stock_transactions (id);
CREATE TABLE IF NOT EXISTS rt_message_receipts (
	message_id VARCHAR NOT NULL, 
	user_id VARCHAR NOT NULL, 
	delivered_at DATETIME, 
	read_at DATETIME, 
	PRIMARY KEY (message_id, user_id), 
	FOREIGN KEY(message_id) REFERENCES rt_messages (id), 
	FOREIGN KEY(user_id) REFERENCES users (id)
);
CREATE TABLE IF NOT EXISTS rt_message_reactions (
	message_id VARCHAR NOT NULL, 
	user_id VARCHAR NOT NULL, 
	emoji VARCHAR NOT NULL, 
	created_at DATETIME, 
	PRIMARY KEY (message_id, user_id, emoji), 
	FOREIGN KEY(message_id) REFERENCES rt_messages (id), 
	FOREIGN KEY(user_id) REFERENCES users (id)
);
CREATE TABLE IF NOT EXISTS rt_pinned_messages (
	conversation_id VARCHAR NOT NULL, 
	message_id VARCHAR NOT NULL, 
	pinned_by VARCHAR NOT NULL, 
	pinned_at DATETIME, 
	PRIMARY KEY (conversation_id, message_id), 
	FOREIGN KEY(conversation_id) REFERENCES rt_conversations (id), 
	FOREIGN KEY(message_id) REFERENCES rt_messages (id), 
	FOREIGN KEY(pinned_by) REFERENCES users (id)
);
CREATE INDEX IF NOT EXISTS ix_rt_pinned_messages_pinned_at ON rt_pinned_messages (pinned_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_rt_messages_sender_client ON rt_messages(sender_id, client_message_id);
