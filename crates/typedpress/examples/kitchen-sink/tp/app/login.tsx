import { LoginForm } from "typedpress/app";

export const metadata = { title: "Sign in" };

export default function Login() {
  return <section><h1>Sign in to the Community Kitchen</h1><LoginForm /></section>;
}
