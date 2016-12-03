# wp-dmarc
DMARC Aggregate Viewer

Collects DMARC aggregate reports from a POP/IMAP account and displays a table and a chart.
Does a daily check.

## Why
Keeping a eye on email delivery is important, this script is a free and easy alternative to the paid services.
If you find this useful and want me to keep working on it, [please buy me a beer](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=QP69FUSN3LNY6).


## Settings
Put this in `config.php` (Check the docs for PHP IMAP, can work with IMAP as well).
```PHP
define('WPDMARC_SERV', '{mail.domain.com:995/pop3/ssl/novalidate-cert}');
define('WPDMARC_NAME', 'pop_email@domain.com');
define('WPDMARC_PASS', 'pop_password');

```

The email address needs to be one used by (AND ONLY BY) your dmarc aggregate collection. Read [the FAQ](https://dmarc.org/wiki/FAQ#When_can_I_expect_to_receive_my_first_aggregate_report.3F).

## Screenshots
![Screnshot](/assets/screenshot.png?raw=true "Screenshot")
