stompie
=============

Native implementation of a PHP Stomp client that's meant to mimic (and in some ways, improve upon by making certain parts stricter) the Stomp extension: http://www.php.net/manual/en/book.stomp.php

## About
It might seem like a stupid idea to write this, but it's meant to eventually be a drop-in replacement for the extension for situations when you cannot install it (it is not officially supported by RHEL for example).

## Differences
* Stompie only handles one subscribtion at a time for simplicity. This means that for example Stompie::ack assumes the frame belongs to the current subscription.
* Stompie supports the NACK command.

## API coverage
Not all of it is supported yet, but this is a work in progress!

### Stomp
Method                | Implemented by
----------------------|----------------
Stomp::abort          | Stompie::abort
Stomp::ack            | Stompie::ack
Stomp::begin          | Stompie::begin
Stomp::commit         | Stompie::commit
Stomp::__construct    | Stompie::__construct
Stomp::__destruct     | Stompie::__destruct
Stomp::error          | -
Stomp::getReadTimeout | Stompie::getReadTimeout
Stomp::getSessionId   | Stompie::getSessionId
Stomp::hasFrame       | Stompie::hasFrame
Stomp::readFrame      | Stompie::readFrame
Stomp::send           | Stompie::send
Stomp::setReadTimeout | Stompie::setReadTimeout
Stomp::subscribe      | Stompie::subscribe 
Stomp::unsubscribe    | Stompie::unsubscribe
-                     | Stompie::nack

### StompFrame
Method                  | Implemented by
------------------------|----------------
StompFrame::__construct | StompieFrame::__construct

Property | Implemented by
---------|----------------
body     | body
headers  | headers
command  | command
