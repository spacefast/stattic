//! Interpreter for the prepared-statement dialect emitted by frozen Zero bundles.
//! Only declared table/column bindings and the old query-builder grammar become
//! SQL. Tenant SQL is never forwarded to the driver, including for legacy code.
use super::*;

pub(super) fn execute(raw: &str, metadata: &EndpointDbMetadata) -> Result<Value, BrokerRefusal> {
    let statement = statement(raw, metadata)?;
    let ready = ready_statement(&statement)?;
    with_invocation_conn(|conn| run_ready_statement(conn, ready))
}

fn refused() -> BrokerRefusal {
    invalid_capability_operation("Legacy Zero DB accepts only declared query-builder operations.")
}

fn statement(raw: &str, metadata: &EndpointDbMetadata) -> Result<DbStatement, BrokerRefusal> {
    if raw.len() > DB_OPERATION_MAX_BYTES {
        return Err(refused());
    }
    let input: DbStatement = serde_json::from_str(raw).map_err(|_| refused())?;
    let tokens = tokenize(&input.sql)?;
    let mut parser = Parser {
        tokens: &tokens,
        position: 0,
        parameters: 0,
    };
    let (sql, write) = parser.statement(metadata)?;
    if parser.position != tokens.len() || parser.parameters != input.params.len() {
        return Err(refused());
    }
    Ok(DbStatement {
        sql,
        params: input.params,
        mode: write.then(|| "execute".into()),
    })
}

#[derive(Debug, PartialEq)]
enum Token {
    Word(String),
    Identifier(String),
    Number(u64),
    Symbol(char),
    Comparison(String),
}

fn tokenize(sql: &str) -> Result<Vec<Token>, BrokerRefusal> {
    let mut chars = sql.chars().peekable();
    let mut tokens = Vec::new();
    while let Some(c) = chars.next() {
        let token = match c {
            c if c.is_ascii_whitespace() => continue,
            '`' => {
                let mut identifier = String::new();
                loop {
                    match chars.next() {
                        Some('`') if chars.peek() == Some(&'`') => {
                            chars.next();
                            identifier.push('`');
                        }
                        Some('`') => break,
                        Some(c) if c != '\0' => identifier.push(c),
                        _ => return Err(refused()),
                    }
                }
                Token::Identifier(identifier)
            }
            c if c.is_ascii_alphabetic() => {
                let mut word = String::from(c);
                while chars.peek().is_some_and(|c| c.is_ascii_alphabetic()) {
                    word.push(chars.next().unwrap());
                }
                Token::Word(word)
            }
            c if c.is_ascii_digit() => {
                let mut number = String::from(c);
                while chars.peek().is_some_and(|c| c.is_ascii_digit()) {
                    number.push(chars.next().unwrap());
                }
                Token::Number(number.parse().map_err(|_| refused())?)
            }
            '=' | '<' | '>' => {
                let mut op = String::from(c);
                if c != '=' && chars.peek() == Some(&'=') {
                    op.push(chars.next().unwrap());
                }
                Token::Comparison(op)
            }
            '(' | ')' | ',' | '?' | '*' => Token::Symbol(c),
            _ => return Err(refused()),
        };
        tokens.push(token);
    }
    Ok(tokens)
}

struct Parser<'a> {
    tokens: &'a [Token],
    position: usize,
    parameters: usize,
}
impl Parser<'_> {
    fn next(&mut self) -> Result<&Token, BrokerRefusal> {
        let token = self.tokens.get(self.position).ok_or_else(refused)?;
        self.position += 1;
        Ok(token)
    }
    fn word(&mut self, word: &str) -> bool {
        if self.tokens.get(self.position) == Some(&Token::Word(word.into())) {
            self.position += 1;
            true
        } else {
            false
        }
    }
    fn require_word(&mut self, word: &str) -> Result<(), BrokerRefusal> {
        if self.word(word) {
            Ok(())
        } else {
            Err(refused())
        }
    }
    fn symbol(&mut self, symbol: char) -> bool {
        if self.tokens.get(self.position) == Some(&Token::Symbol(symbol)) {
            self.position += 1;
            true
        } else {
            false
        }
    }
    fn require_symbol(&mut self, symbol: char) -> Result<(), BrokerRefusal> {
        if self.symbol(symbol) {
            Ok(())
        } else {
            Err(refused())
        }
    }
    fn parameter(&mut self) -> Result<(), BrokerRefusal> {
        self.require_symbol('?')?;
        self.parameters += 1;
        Ok(())
    }
    fn identifier(&mut self) -> Result<String, BrokerRefusal> {
        match self.next()? {
            Token::Identifier(name) => Ok(name.clone()),
            _ => Err(refused()),
        }
    }
    fn number(&mut self) -> Result<u64, BrokerRefusal> {
        match self.next()? {
            Token::Number(value) => Ok(*value),
            _ => Err(refused()),
        }
    }
    fn table<'a>(
        &mut self,
        metadata: &'a EndpointDbMetadata,
    ) -> Result<ResolvedTable<'a>, BrokerRefusal> {
        let physical = self.identifier()?;
        for name in metadata.tables.keys() {
            let table = resolve_table(metadata, name)?;
            if table.physical_name == physical {
                return Ok(table);
            }
        }
        Err(BrokerRefusal::new(
            "zero_db_capability_denied",
            "Zero DB table is not declared.",
        ))
    }
    fn column(&mut self, table: &ResolvedTable<'_>) -> Result<String, BrokerRefusal> {
        let physical = self.identifier()?;
        for name in table.columns.keys() {
            let column = table.column(name)?;
            if column == physical {
                return Ok(quote_mysql_identifier(column));
            }
        }
        Err(BrokerRefusal::new(
            "zero_db_capability_denied",
            "Zero DB field is not declared.",
        ))
    }
    fn equality(&mut self) -> Result<(), BrokerRefusal> {
        if self.next()? == &Token::Comparison("=".into()) {
            Ok(())
        } else {
            Err(refused())
        }
    }
    fn statement(
        &mut self,
        metadata: &EndpointDbMetadata,
    ) -> Result<(String, bool), BrokerRefusal> {
        if self.word("SELECT") {
            return self.select(metadata).map(|sql| (sql, false));
        }
        if self.word("INSERT") {
            self.require_word("INTO")?;
            let table = self.table(metadata)?;
            self.require_symbol('(')?;
            let mut columns = vec![self.column(&table)?];
            while self.symbol(',') {
                columns.push(self.column(&table)?);
            }
            self.require_symbol(')')?;
            self.require_word("VALUES")?;
            self.require_symbol('(')?;
            for index in 0..columns.len() {
                if index > 0 {
                    self.require_symbol(',')?;
                }
                self.parameter()?;
            }
            self.require_symbol(')')?;
            return Ok((
                format!(
                    "INSERT INTO {} ({}) VALUES ({})",
                    quote_mysql_identifier(table.physical_name),
                    columns.join(", "),
                    vec!["?"; columns.len()].join(", ")
                ),
                true,
            ));
        }
        let update = self.word("UPDATE");
        if !update {
            self.require_word("DELETE")?;
            self.require_word("FROM")?;
        }
        let table = self.table(metadata)?;
        let mut sql = if update {
            self.require_word("SET")?;
            let mut assignments = Vec::new();
            loop {
                let column = self.column(&table)?;
                self.equality()?;
                self.parameter()?;
                assignments.push(format!("{column} = ?"));
                if !self.symbol(',') {
                    break;
                }
            }
            format!(
                "UPDATE {} SET {}",
                quote_mysql_identifier(table.physical_name),
                assignments.join(", ")
            )
        } else {
            format!(
                "DELETE FROM {}",
                quote_mysql_identifier(table.physical_name)
            )
        };
        self.require_word("WHERE")?;
        let key = self.column(&table)?;
        if key != quote_mysql_identifier(table.column(table.primary_key)?) {
            return Err(refused());
        }
        self.equality()?;
        self.parameter()?;
        sql.push_str(&format!(" WHERE {key} = ?"));
        Ok((sql, true))
    }
    fn select(&mut self, metadata: &EndpointDbMetadata) -> Result<String, BrokerRefusal> {
        let count = !self.symbol('*');
        if count {
            self.require_word("COUNT")?;
            self.require_symbol('(')?;
            self.require_symbol('*')?;
            self.require_symbol(')')?;
            self.require_word("AS")?;
            if !self.word("count") && self.identifier()? != "count" {
                return Err(refused());
            }
        }
        self.require_word("FROM")?;
        let table = self.table(metadata)?;
        let projection = if count {
            "COUNT(*) AS `count`".into()
        } else {
            table
                .columns
                .keys()
                .map(|name| table.column(name).map(quote_mysql_identifier))
                .collect::<Result<Vec<_>, _>>()?
                .join(", ")
        };
        if projection.is_empty() {
            return Err(refused());
        }
        let mut sql = format!(
            "SELECT {projection} FROM {}",
            quote_mysql_identifier(table.physical_name)
        );
        if self.word("WHERE") {
            sql.push_str(&format!(" WHERE {}", self.condition(&table, 0)?));
        }
        if self.word("ORDER") {
            self.require_word("BY")?;
            let mut order = Vec::new();
            loop {
                let column = self.column(&table)?;
                let direction = if self.word("ASC") {
                    "ASC"
                } else {
                    self.require_word("DESC")?;
                    "DESC"
                };
                order.push(format!("{column} {direction}"));
                if !self.symbol(',') {
                    break;
                }
            }
            sql.push_str(&format!(" ORDER BY {}", order.join(", ")));
        }
        if self.word("LIMIT") {
            sql.push_str(&format!(" LIMIT {}", self.number()?));
        }
        if self.word("OFFSET") {
            sql.push_str(&format!(" OFFSET {}", self.number()?));
        }
        Ok(sql)
    }
    fn condition(
        &mut self,
        table: &ResolvedTable<'_>,
        depth: usize,
    ) -> Result<String, BrokerRefusal> {
        if depth > 16 {
            return Err(refused());
        }
        let mut sql = self.predicate(table, depth)?;
        loop {
            let op = if self.word("AND") {
                "AND"
            } else if self.word("OR") {
                "OR"
            } else {
                break;
            };
            sql.push_str(&format!(" {op} {}", self.predicate(table, depth)?));
        }
        Ok(sql)
    }
    fn predicate(
        &mut self,
        table: &ResolvedTable<'_>,
        depth: usize,
    ) -> Result<String, BrokerRefusal> {
        if self.symbol('(') {
            let sql = self.condition(table, depth + 1)?;
            self.require_symbol(')')?;
            return Ok(format!("({sql})"));
        }
        if self.tokens.get(self.position) == Some(&Token::Number(0)) {
            self.position += 1;
            self.equality()?;
            if self.number()? != 1 {
                return Err(refused());
            }
            return Ok("0 = 1".into());
        }
        let column = self.column(table)?;
        if self.word("IS") {
            let not = self.word("NOT");
            self.require_word("NULL")?;
            return Ok(format!("{column} IS {}NULL", if not { "NOT " } else { "" }));
        }
        let op = match self.next()? {
            Token::Comparison(op) => op.clone(),
            _ => return Err(refused()),
        };
        self.parameter()?;
        Ok(format!("{column} {op} ?"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn metadata() -> EndpointDbMetadata {
        serde_json::from_value(json!({
            "tables": { "todos": {
                "physicalName": "sf_todos", "primaryKey": "id",
                "columns": {
                    "id": { "physicalName": "todo_id", "type": "id" },
                    "title": { "physicalName": "todo_title", "type": "string" }
                }
            }}
        }))
        .unwrap()
    }
    fn compile(sql: &str, params: Value) -> Result<DbStatement, BrokerRefusal> {
        statement(
            &json!({ "sql": sql, "params": params }).to_string(),
            &metadata(),
        )
    }

    #[test]
    fn interprets_frozen_crud_and_keyset_operations_with_bound_values() {
        let insert = compile(
            "INSERT INTO `sf_todos` (`todo_title`) VALUES (?)",
            json!(["'; DROP TABLE users; --"]),
        )
        .unwrap();
        assert_eq!(
            insert.sql,
            "INSERT INTO `sf_todos` (`todo_title`) VALUES (?)"
        );
        assert_eq!(insert.params, vec![json!("'; DROP TABLE users; --")]);
        assert_eq!(insert.mode.as_deref(), Some("execute"));
        let update = compile(
            "UPDATE `sf_todos` SET `todo_title` = ? WHERE `todo_id` = ?",
            json!(["second", "1"]),
        )
        .unwrap();
        assert_eq!(
            update.sql,
            "UPDATE `sf_todos` SET `todo_title` = ? WHERE `todo_id` = ?"
        );
        let delete = compile("DELETE FROM `sf_todos` WHERE `todo_id` = ?", json!(["1"])).unwrap();
        assert_eq!(delete.sql, "DELETE FROM `sf_todos` WHERE `todo_id` = ?");
        let page = compile("SELECT * FROM `sf_todos` WHERE (`todo_title` = ? AND ((`todo_id` < ? OR `todo_id` IS NULL))) ORDER BY `todo_id` DESC LIMIT 51 OFFSET 0", json!(["first", "3"])).unwrap();
        assert_eq!(page.sql, "SELECT `todo_id`, `todo_title` FROM `sf_todos` WHERE (`todo_title` = ? AND ((`todo_id` < ? OR `todo_id` IS NULL))) ORDER BY `todo_id` DESC LIMIT 51 OFFSET 0");
        assert_eq!(page.mode, None);
        let count = compile(
            "SELECT COUNT(*) AS count FROM `sf_todos` WHERE 0 = 1",
            json!([]),
        )
        .unwrap();
        assert_eq!(
            count.sql,
            "SELECT COUNT(*) AS `count` FROM `sf_todos` WHERE 0 = 1"
        );
    }

    #[test]
    fn refuses_undeclared_bindings_in_each_statement_position() {
        for sql in [
            "SELECT * FROM `other_tenant`",
            "SELECT * FROM `sf_todos` WHERE `private_field` = ?",
            "SELECT * FROM `sf_todos` ORDER BY `private_field` ASC",
            "INSERT INTO `sf_todos` (`private_field`) VALUES (?)",
            "UPDATE `sf_todos` SET `private_field` = ? WHERE `todo_id` = ?",
            "DELETE FROM `sf_todos` WHERE `private_field` = ?",
        ] {
            let error = compile(sql, json!([])).unwrap_err();
            assert_eq!(error.code, "zero_db_capability_denied", "{sql}");
        }
    }

    #[test]
    fn refuses_sql_outside_the_prepared_query_builder_dialect() {
        for sql in [
            "SELECT * FROM `sf_todos`; DELETE FROM `sf_todos`",
            "SELECT * FROM `sf_todos` UNION SELECT * FROM `users`",
            "SELECT * FROM `sf_todos` INTO OUTFILE '/tmp/result'",
            "SELECT * FROM `sf_todos` JOIN `users` ON 1 = 1",
            "SELECT * FROM `sf_todos` WHERE `todo_id` = (SELECT 1)",
            "SELECT * FROM `sf_todos` WHERE `todo_id` = 'literal'",
            "SELECT * FROM `sf_todos` WHERE `todo_id` = ? -- trailing",
            "SELECT * FROM `sf_todos` WHERE `todo_id` = ? /* comment */",
            "SELECT * FROM `db`.`sf_todos`",
            "UPDATE `sf_todos` SET `todo_title` = ? WHERE `todo_title` = ?",
            "DELETE FROM `sf_todos`",
            "INSERT INTO `sf_todos` (`todo_title`) SELECT * FROM `users`",
            "SELECT * FROM `sf_todos` LIMIT 18446744073709551616",
        ] {
            let params = vec![json!("bound"); sql.matches('?').count()];
            assert!(compile(sql, json!(params)).is_err(), "{sql}");
        }
        assert!(compile("SELECT * FROM `sf_todos` WHERE `todo_id` = ?", json!([])).is_err());
        assert!(compile("SELECT * FROM `sf_todos`", json!(["extra"])).is_err());
        let nested = format!(
            "SELECT * FROM `sf_todos` WHERE {}0 = 1{}",
            "(".repeat(18),
            ")".repeat(18)
        );
        assert!(compile(&nested, json!([])).is_err());
    }
}
