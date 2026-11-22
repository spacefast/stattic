<?php
declare(strict_types=1);

/**
 * WordPress's session store for a TypedPress binding (typedpress.php): the
 * stock user-meta store, except that a row the platform projects holds no
 * WordPress session. Reading one returns nothing and writing one stores
 * nothing, so no WordPress auth cookie ever signs in as that row.
 */
final class Spacefast_TP_Session_Tokens extends WP_User_Meta_Session_Tokens
{
    protected function get_sessions()
    {
        return spacefast_tp_is_projected((int) $this->user_id) ? [] : parent::get_sessions();
    }

    protected function update_session($verifier, $session = null)
    {
        if (spacefast_tp_is_projected((int) $this->user_id)) return;
        parent::update_session($verifier, $session);
    }
}
