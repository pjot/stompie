stompie
=============

Native implementation of a PHP Stomp client that's meant to mimic (and in some ways, improve upon by making certain parts stricter) the Stomp extension: http://www.php.net/manual/en/book.stomp.php

## About
It might seem like a stupid idea to write this, but it's meant to eventually be a drop-in replacement for the extension for situations when you cannot install it (it is not officially supported by RHEL for example).

## API coverage
Not all of it is supported yet, but this is a work in progress!

### Stomp
Method                | Implemented by
----------------------|----------------
Stomp::abort          | -
Stomp::ack            | Stompie::ack
Stomp::begin          | -
Stomp::commit         | -
Stomp::__construct    | Stompie::__construct
Stomp::__destruct     | Stompie::__destruct
Stomp::error          | -
Stomp::getReadTimeout | -
Stomp::getSessionId   | -
Stomp::hasFrame       | Stompie::hasFrame
Stomp::readFrame      | Stompie::readFrame
Stomp::send           | Stompie::send
Stomp::setReadTimeout | - 
Stomp::subscribe      | Stompie::subscribe 
Stomp::unsubscribe    | -

### StompFrame
Method                  | Implemented by
------------------------|----------------
StompFrame::__construct | StompieFrame::__construct

Property | Implemented by
---------|----------------
body     | body
headers  | headers
command  | command
