<?php
namespace Services\HelloWorld;

class HelloWorldHandler implements HelloWorldIf {
  public function sayHello($name)
  {
      return "Hello $name";
  }
}
