# mbc-mailchimp-status

This is a Message Broker consumer that handles Mailchimp user status updates.

## Setup
#### Prerequisites
- Install Composer: https://getcomposer.org/doc/00-intro.md#installation-nix
- Setup configs:
  - Clone the messagebroker-config repository: https://github.com/DoSomething/messagebroker-config.
  - Create a symlink in the root of `mbc-user-unsubscribe` to `mb-config.inc` in `messagebroker-config`.
  - Create a symlink in the root of `mbc-user-unsubscribe` to wherever the `mb-secure-config.inc` file is.

#### Start the Consumer
- Install dependencies: `composer install`
- Run the consumer: `php mbc-mailchimp-status.php`


[![Bitdeli Badge](https://d2weczhvl823v0.cloudfront.net/DoSomething/mbc-mailchimp-status/trend.png)](https://bitdeli.com/free "Bitdeli Badge")

