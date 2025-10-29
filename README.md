# Swoldier

Swoldier is a minimal micro-framework built on top of Swoole for building high-performance PHP applications.

### Basic Architecture

Swoldier applications operate with two types of workers:

* HTTP Workers: These handle incoming HTTP requests and should be kept lean to maximize cache efficiency and response speed.
* Task Workers: Designed to offload any non-HTTP or long-running tasks, ensuring that HTTP workers remain responsive and performant.

![Swoole-Diagram](./assets/swoole-architecture.svg)


## Usage

Below is a usage example to get your started.

## Links

* [Introduction to swoole](https://phpgoodness.com/articles/introduction-to-swoole.html)