stompie
=============

Native implementation of a PHP Stomp client that's meant to mimic (and in some ways, improve upon by making certain parts stricter) the Stomp extension: http://www.php.net/manual/en/book.stomp.php

## About
It might seem like a stupid idea to write this, but it's meant to eventually be a drop-in replacement for the extension for situations when you cannot install it (it is not officially supported by RHEL for example).

## Differences
* Stomp only handles one subscribtion at a time for simplicity. This means that for example Stomp::ack assumes the frame belongs to the current subscription.
* Stomp supports the NACK command.

## API coverage
Not all of it is supported yet, but this is a work in progress!

### Stomp
Method                | Implemented by
----------------------|----------------
Stomp::abort          | Stomp::abort
Stomp::ack            | Stomp::ack
Stomp::begin          | Stomp::begin
Stomp::commit         | Stomp::commit
Stomp::__construct    | Stomp::__construct
Stomp::__destruct     | Stomp::__destruct
Stomp::error          | -
Stomp::getReadTimeout | Stomp::getReadTimeout
Stomp::getSessionId   | Stomp::getSessionId
Stomp::hasFrame       | Stomp::hasFrame
Stomp::readFrame      | Stomp::readFrame
Stomp::send           | Stomp::send
Stomp::setReadTimeout | Stomp::setReadTimeout
Stomp::subscribe      | Stomp::subscribe 
Stomp::unsubscribe    | Stomp::unsubscribe
-                     | Stomp::nack

### StompFrame
Method                  | Implemented by
------------------------|----------------
StompFrame::__construct | StompFrame::__construct

Property | Implemented by
---------|----------------
body     | body
headers  | headers
command  | command
